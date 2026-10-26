<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantWhatsAppAccount;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAdminSettingsController extends Controller
{
    /**
     * GET /api/auth/admin/integrations/whatsapp/status
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.read');

        $settingService = app(\App\Services\PlatformSettingService::class);
        $appSecret = $settingService->get('whatsapp_app_secret') ?: config('services.whatsapp.app_secret');
        $verifyToken = $settingService->get('whatsapp_verify_token') ?: config('services.whatsapp.verify_token');
        $graphVersion = $settingService->get('whatsapp_graph_version') ?: config('services.whatsapp.graph_version', 'v23.0');

        $isConfigured = ! empty($appSecret) && ! empty($verifyToken);
        $webhookVerificationConfigured = ! empty($verifyToken);

        $totalEvents = WhatsAppWebhookEvent::count();
        $processedEvents = WhatsAppWebhookEvent::where('processing_status', 'processed')->count();
        $failedEvents = WhatsAppWebhookEvent::where('processing_status', 'failed')->count();
        $receivedEvents = WhatsAppWebhookEvent::where('processing_status', 'received')->count();

        $latestWebhook = WhatsAppWebhookEvent::latest('created_at')->first();
        $latestSuccessfulWebhook = WhatsAppWebhookEvent::where('processing_status', 'processed')
            ->latest('processed_at')
            ->first();

        $latestSyncedAccount = TenantWhatsAppAccount::whereNotNull('last_synced_at')
            ->latest('last_synced_at')
            ->first();

        // Check recent failure for sanitized error
        $recentFailedEvent = WhatsAppWebhookEvent::where('processing_status', 'failed')
            ->latest('updated_at')
            ->first();

        return response()->json([
            'is_configured' => $isConfigured,
            'webhook_verification_configured' => $webhookVerificationConfigured,
            'graph_version' => $graphVersion,
            'health' => $failedEvents > 0 && ($failedEvents / max(1, $totalEvents) > 0.2) ? 'degraded' : 'healthy',
            'latest_webhook_at' => $latestWebhook?->created_at?->toIso8601String(),
            'latest_successful_webhook_at' => $latestSuccessfulWebhook?->processed_at?->toIso8601String(),
            'latest_sync_at' => $latestSyncedAccount?->last_synced_at?->toIso8601String(),
            'events_summary' => [
                'total' => $totalEvents,
                'processed' => $processedEvents,
                'failed' => $failedEvents,
                'received' => $receivedEvents,
            ],
            'recent_error' => $recentFailedEvent ? $recentFailedEvent->last_error : null,
            'missing_configuration' => [
                'app_secret' => empty($appSecret),
                'verify_token' => empty($verifyToken),
            ],
        ]);
    }

    /**
     * PATCH /api/auth/admin/integrations/whatsapp/config
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.manage');

        $data = $request->validate([
            'graph_version' => ['sometimes', 'string', 'max:10', 'regex:/^v\d+\.\d+$/'],
        ]);

        if (isset($data['graph_version'])) {
            $settingService = app(\App\Services\PlatformSettingService::class);
            $settingService->set('whatsapp_graph_version', trim($data['graph_version']), 'integrations', 'string');
            config(['services.whatsapp.graph_version' => trim($data['graph_version'])]);
        }

        $settingService = app(\App\Services\PlatformSettingService::class);
        $currentGraphVersion = $settingService->get('whatsapp_graph_version') ?: config('services.whatsapp.graph_version', 'v23.0');

        return response()->json([
            'message' => 'WhatsApp integration settings updated successfully.',
            'graph_version' => $currentGraphVersion,
        ]);
    }

    protected function authorizeAdmin($user, string $permission): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('Super Admin') || $user->hasRole('super_admin');
        if (! $isSuperAdmin && ! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "Forbidden. Required permission: {$permission}.");
        }
    }
}
