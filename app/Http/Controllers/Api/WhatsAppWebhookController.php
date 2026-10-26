<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\TenantWhatsAppAccount;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta Webhook Verification challenge (GET).
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $settingService = app(\App\Services\PlatformSettingService::class);
        $verifyToken = $settingService->get('whatsapp_verify_token') ?: config('services.whatsapp.verify_token');

        if (
            $mode === 'subscribe'
            && is_string($verifyToken)
            && $verifyToken !== ''
            && is_string($token)
            && hash_equals($verifyToken, $token)
        ) {
            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'success' => false,
            'message' => 'Webhook verification failed.',
        ], 403);
    }

    /**
     * Meta Webhook Inbound Delivery (POST).
     * Validates X-Hub-Signature-256, records durable events, and dispatches processing.
     */
    public function receive(Request $request): JsonResponse
    {
        // 1. Verify Meta webhook signature
        $settingService = app(\App\Services\PlatformSettingService::class);
        $appSecret = $settingService->getWebhookSecret('whatsapp');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (
            ! is_string($appSecret)
            || $appSecret === ''
            || ! str_starts_with($signature, 'sha256=')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook signature is missing or not configured.',
            ], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expected, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        // 2. Validate webhook object
        $payload = $request->json()->all();

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json([
                'success' => true,
                'message' => 'Event ignored.',
            ]);
        }

        $processedCount = 0;

        foreach (($payload['entry'] ?? []) as $entry) {
            $wabaId = $entry['id'] ?? null;

            foreach (($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $phoneNumberId = data_get($value, 'metadata.phone_number_id');

                // Determine internal event identifier and idempotency key
                $firstMsgId = data_get($value, 'messages.0.id');
                $firstStatusId = data_get($value, 'statuses.0.id');
                $eventId = $firstMsgId ?? $firstStatusId ?? ('evt_' . substr(md5(json_encode($value)), 0, 16));

                // Idempotency key per unique message/status or change payload
                $idempotencyKey = $eventId;
                if (! $firstMsgId && ! $firstStatusId) {
                    $idempotencyKey = 'change_' . hash('sha256', json_encode($value));
                }

                $eventType = ! empty($value['messages']) ? 'messages' : (! empty($value['statuses']) ? 'statuses' : 'change');

                // Attempt to resolve tenant
                $account = null;
                if ($phoneNumberId) {
                    $account = TenantWhatsAppAccount::query()
                        ->where('phone_number_id', $phoneNumberId)
                        ->first();
                }

                // Check for existing webhook event with this idempotency key
                $existingEvent = WhatsAppWebhookEvent::where('idempotency_key', $idempotencyKey)->first();

                if ($existingEvent) {
                    // Already recorded; if already processed or receiving concurrent delivery, skip
                    continue;
                }

                // Persist durable event record
                try {
                    $webhookEvent = WhatsAppWebhookEvent::create([
                        'event_id' => $eventId,
                        'idempotency_key' => $idempotencyKey,
                        'event_type' => $eventType,
                        'tenant_id' => $account?->tenant_id,
                        'phone_number_id' => $phoneNumberId,
                        'waba_id' => $wabaId,
                        'payload' => $value,
                        'processing_status' => 'received',
                        'attempt_count' => 0,
                    ]);

                    // Dispatch async processing or execute synchronously if sync queue
                    ProcessWhatsAppWebhookJob::dispatchSync($webhookEvent);
                    $processedCount++;
                } catch (\Throwable $e) {
                    Log::error('Error recording/dispatching WhatsApp webhook event: ' . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received and processed.',
            'events_count' => $processedCount,
        ]);
    }
}
