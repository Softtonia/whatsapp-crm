<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\RazorpayConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RazorpayAdminConfigController extends Controller
{
    public function __construct(
        protected RazorpayConfigService $configService,
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * GET /api/auth/admin/integrations/razorpay/config
     * Retrieve current Razorpay gateway configuration (masked secrets).
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.read');

        $config = $this->configService->getConfig();

        return response()->json([
            'success' => true,
            'message' => 'Razorpay configuration retrieved successfully.',
            'data' => $config,
        ]);
    }

    /**
     * PUT/PATCH /api/auth/admin/integrations/razorpay/config
     * Update dynamic Razorpay configuration.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.manage');

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:test,live'],
            'key_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'key_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $setting = $this->configService->updateConfig($validated);

        // Audit log the update (sensitive secrets are sanitized automatically)
        $this->activityLogService->log(
            eventType: 'integration.razorpay.updated',
            category: 'settings',
            description: 'Updated dynamic Razorpay configuration',
            actor: $request->user(),
            subjectType: \App\Models\PlatformSetting::class,
            metadata: [
                'is_active' => $setting['is_active'] ?? false,
                'mode' => $setting['mode'] ?? 'test',
                'key_id' => $setting['key_id'] ?? null,
            ],
            request: $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Razorpay configuration updated successfully.',
            'data' => $this->configService->getConfig(),
        ]);
    }

    /**
     * POST /api/auth/admin/integrations/razorpay/test-connection
     * Test connection to Razorpay API using configured or supplied credentials.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.manage');

        $validated = $request->validate([
            'key_id' => ['sometimes', 'nullable', 'string'],
            'key_secret' => ['sometimes', 'nullable', 'string'],
        ]);

        $result = $this->configService->testConnection(
            keyId: $validated['key_id'] ?? null,
            keySecret: $validated['key_secret'] ?? null
        );

        $this->activityLogService->log(
            eventType: 'integration.razorpay.test_connection',
            category: 'settings',
            description: 'Tested Razorpay connection: ' . $result['status'],
            actor: $request->user(),
            status: $result['success'] ? 'success' : 'failure',
            metadata: [
                'test_status' => $result['status'],
                'message' => $result['message'],
            ],
            request: $request
        );

        $statusCode = $result['success'] ? 200 : 422;

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result,
        ], $statusCode);
    }

    /**
     * POST /api/auth/admin/integrations/razorpay/sync-webhook
     * Sync and return verified webhook endpoint information for Razorpay Dashboard.
     */
    public function syncWebhook(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.manage');

        $defaultWebhookUrl = url('/api/v1/webhooks/payments/razorpay');

        $this->configService->updateConfig([
            'webhook_url' => $defaultWebhookUrl,
        ]);

        $config = $this->configService->getConfig();

        return response()->json([
            'success' => true,
            'message' => 'Razorpay webhook configuration synchronized.',
            'data' => [
                'webhook_url' => $defaultWebhookUrl,
                'recommended_events' => [
                    'payment.captured',
                    'payment.failed',
                    'order.paid',
                    'invoice.paid',
                ],
                'has_webhook_secret' => $config['has_webhook_secret'],
            ],
        ]);
    }

    protected function authorizeAdmin($user, string $permission): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->hasRole('platform_owner');
        if (! $isSuperAdmin && ! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "Forbidden. Required permission: {$permission}.");
        }
    }
}
