<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * List audit logs with comprehensive filtering and pagination (SA-11).
     * Requires permission: audit.read
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'audit.read');

        $validated = $request->validate([
            'event_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'actor_id' => ['sometimes', 'nullable', 'integer'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'tenant_id' => ['sometimes', 'nullable', 'integer'],
            'resource_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'resource_id' => ['sometimes', 'nullable', 'integer'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'result' => ['sometimes', 'nullable', 'string', 'max:30'],
            'request_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ActivityLog::query()
            ->with([
                'actor:id,first_name,last_name,email',
                'tenant:id,company_name,status',
            ]);

        // Action / Event Type
        $action = $validated['action'] ?? $validated['event_type'] ?? null;
        if ($action) {
            $query->where('event_type', $action);
        }

        // Category
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        // Actor / User ID
        $actorId = $validated['actor_id'] ?? $validated['user_id'] ?? null;
        if ($actorId) {
            $query->where('actor_user_id', $actorId);
        }

        // Tenant ID
        if (! empty($validated['tenant_id'])) {
            $query->where('tenant_id', $validated['tenant_id']);
        }

        // Resource / Subject Type & ID
        $resourceType = $validated['resource_type'] ?? $validated['subject_type'] ?? null;
        if ($resourceType) {
            $query->where(function ($q) use ($resourceType) {
                $q->where('subject_type', $resourceType)
                    ->orWhere('subject_type', 'like', "%{$resourceType}%");
            });
        }

        $resourceId = $validated['resource_id'] ?? $validated['subject_id'] ?? null;
        if ($resourceId) {
            $query->where('subject_id', $resourceId);
        }

        // Result / Status
        $status = $validated['status'] ?? $validated['result'] ?? null;
        if ($status) {
            $query->where('status', $status);
        }

        // Request / Correlation ID
        $requestId = $validated['request_id'] ?? $validated['correlation_id'] ?? null;
        if ($requestId) {
            $query->where('request_id', $requestId);
        }

        // IP address
        if (! empty($validated['ip_address'])) {
            $query->where('ip_address', $validated['ip_address']);
        }

        // Date range
        $from = $validated['from'] ?? $validated['start_date'] ?? null;
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $validated['to'] ?? $validated['end_date'] ?? null;
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = $validated['per_page'] ?? 20;
        $paginated = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        $safeItems = collect($paginated->items())->map(fn ($log) => $this->transformLog($log));

        return response()->json([
            'success' => true,
            'message' => 'Audit logs retrieved successfully.',
            'data' => $safeItems,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single audit log record by ID with recursive secret redaction (SA-11).
     * Requires permission: audit.read
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizePermission($request, 'audit.read');

        $log = ActivityLog::query()
            ->with([
                'actor:id,first_name,last_name,email',
                'tenant:id,company_name,status',
            ])
            ->find($id);

        if (! $log) {
            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Audit log retrieved successfully.',
            'data' => $this->transformLog($log, detailed: true),
        ]);
    }

    /**
     * Login Activity API (SA-11).
     * Lists authentication attempts (success, failed, invalid credentials, mfa failures, session revocations).
     * Requires permission: security.read
     */
    public function loginActivity(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'security.read');

        $validated = $request->validate([
            'event_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'result' => ['sometimes', 'nullable', 'string', 'max:30'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $authEventTypes = [
            'auth.login_success',
            'auth.login_failed',
            'auth.login_blocked',
            'mfa.verify_success',
            'mfa.verify_failed',
            'admin.session_revoked',
            'admin.all_sessions_revoked',
            'admin.password_changed',
            'admin.email_changed',
            'admin_user_deactivated',
            'admin_user_reactivated',
        ];

        $query = ActivityLog::query()
            ->with('actor:id,first_name,last_name,email')
            ->where(function ($q) use ($authEventTypes, $validated) {
                if (! empty($validated['event_type'])) {
                    $q->where('event_type', $validated['event_type']);
                } else {
                    $q->whereIn('event_type', $authEventTypes)
                        ->orWhere('category', 'authentication')
                        ->orWhere('category', 'security');
                }
            });

        if (! empty($validated['actor_id'])) {
            $query->where('actor_user_id', $validated['actor_id']);
        }

        $status = $validated['status'] ?? $validated['result'] ?? null;
        if ($status) {
            $query->where('status', $status);
        }

        if (! empty($validated['ip_address'])) {
            $query->where('ip_address', $validated['ip_address']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $paginated = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        $safeItems = collect($paginated->items())->map(fn ($log) => $this->transformLog($log, detailed: true));

        return response()->json([
            'success' => true,
            'message' => 'Login activity retrieved successfully.',
            'data' => $safeItems,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Format and sanitize log records defensively.
     */
    protected function transformLog(ActivityLog $log, bool $detailed = false): array
    {
        $actorData = null;
        if ($log->actor) {
            $full = trim(($log->actor->first_name ?? '') . ' ' . ($log->actor->last_name ?? ''));
            $actorData = [
                'id' => $log->actor->id,
                'email' => $log->actor->email,
                'name' => $full !== '' ? $full : $log->actor->email,
            ];
        }

        $tenantData = null;
        if ($log->tenant) {
            $tenantData = [
                'id' => $log->tenant->id,
                'company_name' => $log->tenant->company_name,
                'status' => $log->tenant->status,
            ];
        }

        $res = [
            'id' => $log->id,
            'actor_user_id' => $log->actor_user_id,
            'created_at' => $log->created_at?->toIso8601String(),
            'timestamp' => $log->created_at?->toIso8601String(),
            'event_type' => $log->event_type,
            'action' => $log->event_type,
            'category' => $log->category,
            'description' => $log->description,
            'actor_type' => $log->actor_type,
            'actor_id' => $log->actor_user_id,
            'actor' => $actorData,
            'tenant_id' => $log->tenant_id,
            'tenant' => $tenantData,
            'resource_type' => $log->subject_type,
            'resource_id' => $log->subject_id,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
            'correlation_id' => $log->request_id,
            'status' => $log->status ?? 'success',
            'result' => $log->status ?? 'success',
            'metadata' => $log->metadata ? $this->activityLogService->sanitizeMetadata($log->metadata) : null,
        ];

        if ($detailed) {
            $res['old_values'] = $log->old_values ? $this->activityLogService->sanitizeMetadata($log->old_values) : null;
            $res['new_values'] = $log->new_values ? $this->activityLogService->sanitizeMetadata($log->new_values) : null;
        }

        return $res;
    }

    /**
     * Authorize permission or super admin / platform owner.
     */
    protected function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        // Platform owners and Super Admins have full access
        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) {
            return;
        }

        if (! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "You do not have the required permission: {$permission}.");
        }
    }
}
