<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantProvisioningJob;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantProvisioningController extends Controller
{
    protected function authorizePermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists()) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission) || $user->hasPermissionTo($permission, 'web')) {
                return;
            }
        }

        abort(403, "You do not have the required permission: {$permissions[0]}.");
    }

    public function start(
        Request $request,
        Tenant $tenant,
        TenantProvisioningService $service
    ): JsonResponse {
        $this->authorizePermission($request, 'tenants.provision', 'tenants.create');
        $data = $request->validate([
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'plan_id' => [
                'required',
                'integer',
                'exists:subscription_plans,id',
            ],
        ]);

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 255) {
            return response()->json([
                'success' => false,
                'message' => 'A valid Idempotency-Key header is required.',
            ], 422);
        }

        $job = TenantProvisioningJob::query()
            ->where('idempotency_key', $key)
            ->first();

        if ($job) {
            if ((int) $job->tenant_id !== (int) $tenant->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This idempotency key is already used by another tenant.',
                ], 409);
            }

            if ($job->status === 'completed') {
                return response()->json([
                    'success' => true,
                    'message' => 'Provisioning already completed.',
                    'data' => $job,
                ]);
            }

            if ($job->status === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'This provisioning job failed. Use the retry endpoint.',
                    'data' => $job,
                ], 409);
            }

            if ($job->status === 'processing') {
                return response()->json([
                    'success' => true,
                    'message' => 'Provisioning is already processing.',
                    'data' => $job,
                ], 202);
            }
        } else {
            $job = TenantProvisioningJob::create([
                'tenant_id' => $tenant->id,
                'requested_by' => $request->user()?->id,
                'status' => 'pending',
                'step' => 'queued',
                'attempts' => 0,
                'idempotency_key' => $key,
                'payload' => $data,
                'completed_steps' => [],
            ]);
        }

        $job = $service->run($job);

        return response()->json([
            'success' => $job->status === 'completed',
            'message' => $job->status === 'completed'
                ? 'Tenant provisioning completed.'
                : 'Tenant provisioning failed. Correct the issue and retry.',
            'data' => $job,
        ], $job->status === 'completed' ? 200 : 422);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizePermission($request, 'tenants.provision', 'tenants.read', 'tenants.view');
        $job = TenantProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        if (! $job) {
            return response()->json([
                'success' => false,
                'message' => 'No provisioning job exists for this tenant.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $job,
        ]);
    }

    public function retry(
        Request $request,
        Tenant $tenant,
        TenantProvisioningService $service
    ): JsonResponse {
        $this->authorizePermission($request, 'tenants.provision', 'tenants.create');
        $job = TenantProvisioningJob::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        if (! $job) {
            return response()->json([
                'success' => false,
                'message' => 'No provisioning job exists for this tenant.',
            ], 404);
        }

        if ($job->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Only failed provisioning jobs can be retried.',
                'data' => $job,
            ], 409);
        }

        $job = $service->run($job);

        return response()->json([
            'success' => $job->status === 'completed',
            'message' => $job->status === 'completed'
                ? 'Provisioning retry completed.'
                : 'Provisioning retry failed.',
            'data' => $job,
        ], $job->status === 'completed' ? 200 : 422);
    }
}
