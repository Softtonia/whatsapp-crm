<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackgroundJobMonitoringService;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemMonitoringController extends Controller
{
    public function __construct(
        protected SystemHealthService $healthService,
        protected BackgroundJobMonitoringService $jobService
    ) {}

    /**
     * Authorize specific monitoring permission or platform admin.
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

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) {
            return;
        }

        if (! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "You do not have the required permission: {$permission}.");
        }
    }

    /**
     * GET /api/auth/admin/monitoring/health
     * Deep system health report for administrators. Requires permission: monitoring.read
     */
    public function health(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'monitoring.read');

        $report = $this->healthService->getHealthReport();
        $statusCode = match ($report['status']) {
            'healthy' => 200,
            'degraded' => 200, // Degraded still returns 200 with component warnings
            default => 503,
        };

        return response()->json([
            'success' => true,
            'message' => 'System health report generated successfully.',
            'data' => $report,
        ], $statusCode);
    }

    /**
     * GET /api/monitoring/liveness
     * Unauthenticated lightweight process probe for k8s/load-balancers.
     */
    public function liveness(): JsonResponse
    {
        return response()->json($this->healthService->checkLiveness());
    }

    /**
     * GET /api/monitoring/readiness
     * Lightweight readiness probe verifying core dependencies (database).
     */
    public function readiness(): JsonResponse
    {
        $readiness = $this->healthService->checkReadiness();
        $code = $readiness['status'] === 'ready' ? 200 : 503;

        return response()->json($readiness, $code);
    }

    /**
     * GET /api/auth/admin/monitoring/jobs
     * Paginated list of background queue and failed jobs. Requires permission: monitoring.read
     */
    public function jobs(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'monitoring.read');

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'in:failed,pending,running,retried'],
            'queue' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job_class' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = $this->jobService->getJobs($validated, $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Background jobs retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/auth/admin/monitoring/jobs/{id}
     * Job details and sanitized error summary. Requires permission: monitoring.read
     */
    public function showJob(Request $request, string $id): JsonResponse
    {
        $this->authorizePermission($request, 'monitoring.read');

        $job = $this->jobService->getJobDetails($id);

        if (! $job) {
            return response()->json([
                'success' => false,
                'message' => "Job with ID {$id} not found.",
                'error' => 'not_found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Job details retrieved successfully.',
            'data' => $job,
        ]);
    }

    /**
     * POST /api/auth/admin/monitoring/jobs/{id}/retry
     * Concurrency-safe retry for failed background jobs. Requires permission: monitoring.retry
     */
    public function retryJob(Request $request, string $id): JsonResponse
    {
        $this->authorizePermission($request, 'monitoring.retry');

        $result = $this->jobService->retryJob($id, $request->user()?->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'status' => $result['status'],
            'data' => $result['data'] ?? null,
        ], $result['code'] ?? 200);
    }
}
