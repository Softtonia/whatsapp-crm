<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use App\Services\TenantHealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantHealthCheckController extends Controller
{
    /**
     * List health checks for a tenant.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                Rule::in(['healthy', 'warning', 'critical', 'unknown']),
            ],
            'category' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $checks = $tenant->healthChecks()
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )
            ->when(
                isset($validated['category']),
                fn ($query) => $query->where(
                    'category',
                    $validated['category']
                )
            )
            ->orderByDesc('checked_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'message' => 'Tenant health checks retrieved successfully.',
            'data' => $checks,
        ]);
    }

    /**
     * Create a health-check record or update an existing record.
     */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'check_key' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'max:50'],
            'status' => [
                'required',
                Rule::in(['healthy', 'warning', 'critical', 'unknown']),
            ],
            'message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'details' => ['sometimes', 'nullable', 'array'],
            'checked_at' => ['sometimes', 'nullable', 'date'],
            'next_check_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $values = $validated;

        if (empty($values['checked_at'])) {
            $values['checked_at'] = now();
        }

        $check = $tenant->healthChecks()->updateOrCreate(
            ['check_key' => $values['check_key']],
            $values
        );

        return response()->json([
            'message' => 'Tenant health check saved successfully.',
            'data' => $check->fresh(),
        ], 200);
    }

    /**
     * Run all configured health checks for this tenant.
     */
    public function run(
        Tenant $tenant,
        TenantHealthCheckService $service
    ): JsonResponse {
        $result = $service->runForTenant($tenant);

        return response()->json([
            'message' => 'Tenant health checks completed.',
            'data' => $result,
        ]);
    }

    /**
     * Show one health-check record belonging to the specified tenant.
     */
    public function show(
        Tenant $tenant,
        TenantHealthCheck $healthCheck
    ): JsonResponse {
        abort_unless(
            (int) $healthCheck->tenant_id === (int) $tenant->id,
            404,
            'Health check not found for this tenant.'
        );

        return response()->json([
            'data' => $healthCheck,
        ]);
    }

    /**
     * Update an existing health-check record.
     */
    public function update(
        Request $request,
        Tenant $tenant,
        TenantHealthCheck $healthCheck
    ): JsonResponse {
        abort_unless(
            (int) $healthCheck->tenant_id === (int) $tenant->id,
            404,
            'Health check not found for this tenant.'
        );

        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:50'],
            'status' => [
                'sometimes',
                Rule::in(['healthy', 'warning', 'critical', 'unknown']),
            ],
            'message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'details' => ['sometimes', 'nullable', 'array'],
            'checked_at' => ['sometimes', 'nullable', 'date'],
            'next_check_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $healthCheck->fill($validated);
        $healthCheck->save();

        return response()->json([
            'message' => 'Tenant health check updated successfully.',
            'data' => $healthCheck->fresh(),
        ]);
    }
}
