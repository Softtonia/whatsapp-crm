<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantAuditController extends Controller
{
    /**
     * List a tenant's activity logs with filters and pagination.
     */
    public function activity(Request $request, Tenant $tenant): JsonResponse
    {
        $filters = $request->validate([
            'event_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = TenantActivityLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('actor:id,first_name,last_name,email')
            ->when(
                $filters['event_type'] ?? null,
                fn ($query, $value) => $query->where('event_type', $value)
            )
            ->when(
                $filters['actor_user_id'] ?? null,
                fn ($query, $value) => $query->where('actor_user_id', $value)
            )
            ->when(
                $filters['from'] ?? null,
                fn ($query, $value) => $query->whereDate('created_at', '>=', $value)
            )
            ->when(
                $filters['to'] ?? null,
                fn ($query, $value) => $query->whereDate('created_at', '<=', $value)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($logs);
    }

    /**
     * List a tenant's status changes with pagination.
     */
    public function statusHistory(Request $request, Tenant $tenant): JsonResponse
    {
        $filters = $request->validate([
            'new_status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['active', 'inactive', 'suspended', 'archived']),
            ],
            'performed_by' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $history = TenantStatusHistory::query()
            ->where('tenant_id', $tenant->id)
            ->with('performer:id,first_name,last_name,email')
            ->when(
                $filters['new_status'] ?? null,
                fn ($query, $value) => $query->where('new_status', $value)
            )
            ->when(
                $filters['performed_by'] ?? null,
                fn ($query, $value) => $query->where('performed_by', $value)
            )
            ->when(
                $filters['from'] ?? null,
                fn ($query, $value) => $query->whereDate('effective_at', '>=', $value)
            )
            ->when(
                $filters['to'] ?? null,
                fn ($query, $value) => $query->whereDate('effective_at', '<=', $value)
            )
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($history);
    }
}
