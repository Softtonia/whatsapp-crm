<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTenantBulkOperationJob;
use App\Models\Tenant;
use App\Models\TenantBulkOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantBulkOperationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'pending',
                    'processing',
                    'completed',
                    'failed',
                    'partial_failed',
                ]),
            ],
            'operation_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['activate', 'suspend', 'archive']),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $operations = TenantBulkOperation::query()
            ->with('initiator:id,first_name,last_name,email')
            ->when(
                $filters['status'] ?? null,
                fn($query, $status) => $query->where('status', $status)
            )
            ->when(
                $filters['operation_type'] ?? null,
                fn($query, $type) => $query->where('operation_type', $type)
            )
            ->latest()
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($operations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation_type' => [
                'required',
                'string',
                Rule::in(['activate', 'suspend', 'archive']),
            ],
            'tenant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'tenant_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:tenants,id',
            ],
            'reason' => [
                'required_if:operation_type,suspend',
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $operation = DB::transaction(function () use ($request, $validated) {
            $tenantIds = collect($validated['tenant_ids'])
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            $operation = TenantBulkOperation::create([
                'operation_type' => $validated['operation_type'],
                'status' => 'pending',
                'initiated_by' => $request->user()->id,
                'filters' => ['tenant_ids' => $tenantIds->all()],
                'options' => [
                    'reason' => $validated['reason'] ?? null,
                ],
                'total_items' => $tenantIds->count(),
                'processed_items' => 0,
                'successful_items' => 0,
                'failed_items' => 0,
            ]);

            foreach ($tenantIds as $tenantId) {
                $operation->items()->create([
                    'tenant_id' => $tenantId,
                    'item_type' => 'tenant',
                    'item_id' => $tenantId,
                    'status' => 'pending',
                    'idempotency_key' => "tenant:{$tenantId}",
                ]);
            }

            return $operation;
        });

        ProcessTenantBulkOperationJob::dispatch($operation->id);

        return response()->json([
            'message' => 'Tenant bulk operation queued successfully.',
            'data' => $operation->load('items'),
        ], 202);
    }
    public function cancel(
        Request $request,
        TenantBulkOperation $operation
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = DB::transaction(function () use (
            $operation,
            $request,
            $validated
        ): array {
            $lockedOperation = TenantBulkOperation::query()
                ->lockForUpdate()
                ->findOrFail($operation->id);

            if (! in_array(
                $lockedOperation->status,
                ['pending', 'processing'],
                true
            )) {
                return [
                    'conflict' => true,
                    'message' => 'Only pending or processing operations can be cancelled.',
                ];
            }

            $options = $lockedOperation->options ?? [];

            $options['cancellation'] = [
                'reason' => $validated['reason'],
                'cancelled_by' => $request->user()?->id,
                'cancelled_at' => now()->toISOString(),
            ];

            $lockedOperation->items()
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'message' => 'Item cancelled by administrator.',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            $lockedOperation->update([
                'status' => 'cancelled',
                'options' => $options,
                'error_summary' => 'Cancelled: ' . $validated['reason'],
                'completed_at' => now(),
            ]);

            return [
                'conflict' => false,
                'operation' => $lockedOperation->fresh()->load('items'),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant bulk operation cancelled.',
            'data' => $result['operation'],
        ]);
    }
    public function show(TenantBulkOperation $operation): JsonResponse
    {
        return response()->json([
            'data' => $operation->load('initiator:id,first_name,last_name,email'),
        ]);
    }

    public function items(Request $request, TenantBulkOperation $operation): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['pending', 'processing', 'completed', 'failed']),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $operation->items()
            ->with('business:id,company_name,slug,status')
            ->when(
                $validated['status'] ?? null,
                fn($query, $status) => $query->where('status', $status)
            )
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($items);
    }

    public function retry(TenantBulkOperation $operation): JsonResponse
    {
        if (! in_array($operation->status, ['failed', 'partial_failed'], true)) {
            return response()->json([
                'message' => 'Only failed or partially failed operations can be retried.',
            ], 409);
        }

        $failedCount = DB::transaction(function () use ($operation) {
            $lockedOperation = TenantBulkOperation::query()
                ->lockForUpdate()
                ->findOrFail($operation->id);

            $failedItems = $lockedOperation->items()
                ->where('status', 'failed')
                ->lockForUpdate()
                ->get();

            foreach ($failedItems as $item) {
                $item->update([
                    'status' => 'pending',
                    'message' => null,
                    'result' => null,
                    'processed_at' => null,
                ]);
            }

            if ($failedItems->isNotEmpty()) {
                $lockedOperation->update([
                    'status' => 'pending',
                    'failed_items' => 0,
                    'completed_at' => null,
                    'error_summary' => null,
                ]);
            }

            return $failedItems->count();
        });

        if ($failedCount === 0) {
            return response()->json([
                'message' => 'No failed items are available to retry.',
            ], 409);
        }

        ProcessTenantBulkOperationJob::dispatch($operation->id);

        return response()->json([
            'message' => 'Failed tenant items queued for retry.',
            'retry_items' => $failedCount,
        ], 202);
    }
}
