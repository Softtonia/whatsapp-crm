<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\TenantWhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\AiAssistantService;
use App\Services\ContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public WhatsAppWebhookEvent $event
    ) {}

    public function handle(): void
    {
        $event = $this->event->fresh();

        if (! $event || $event->processing_status === 'processed') {
            return;
        }

        $event->increment('attempt_count');
        $event->update([
            'last_attempt_at' => now(),
        ]);

        try {
            $payload = $event->payload;
            $value = $payload['value'] ?? $payload;

            $phoneNumberId = data_get($value, 'metadata.phone_number_id') ?? $event->phone_number_id;

            // 1. Resolve tenant WhatsApp account
            $account = null;
            if ($phoneNumberId) {
                $account = TenantWhatsAppAccount::query()
                    ->where('phone_number_id', $phoneNumberId)
                    ->where('status', 'active')
                    ->first();
            }

            if (! $account) {
                // If phone number is unknown or unconfigured, record failure safely without mutating arbitrary tenant
                $event->update([
                    'processing_status' => 'failed',
                    'last_error' => 'No active tenant WhatsApp account found for phone_number_id: ' . ($phoneNumberId ?? 'null'),
                ]);
                return;
            }

            // Ensure event is tied to resolved tenant
            if (! $event->tenant_id) {
                $event->update(['tenant_id' => $account->tenant_id]);
            }

            $profiles = collect($value['contacts'] ?? [])->keyBy('wa_id');

            // 2. Process inbound messages
            foreach (($value['messages'] ?? []) as $incoming) {
                $phone = (string) ($incoming['from'] ?? '');
                $providerId = $incoming['id'] ?? null;

                if ($phone === '' || ! is_string($providerId) || $providerId === '') {
                    continue;
                }

                // Deduplicate message
                if (WhatsAppMessage::query()->where('provider_message_id', $providerId)->exists()) {
                    continue;
                }

                $profileName = data_get($profiles->get($phone), 'profile.name');

                $result = DB::transaction(function () use ($account, $phone, $profileName, $incoming, $providerId) {
                    if (WhatsAppMessage::query()->where('provider_message_id', $providerId)->exists()) {
                        return null;
                    }

                    $conversation = Conversation::firstOrCreate(
                        [
                            'tenant_id' => $account->tenant_id,
                            'customer_phone' => $phone,
                        ],
                        [
                            'whatsapp_account_id' => $account->id,
                            'customer_name' => $profileName,
                            'status' => 'open',
                            'unread_count' => 0,
                            'is_human_takeover' => false,
                        ]
                    );

                    if ((int) $conversation->whatsapp_account_id !== (int) $account->id) {
                        $conversation->whatsapp_account_id = $account->id;
                    }

                    if ($profileName && ! $conversation->customer_name) {
                        $conversation->customer_name = $profileName;
                    }

                    $type = $incoming['type'] ?? 'unknown';
                    $mediaObject = is_array($incoming[$type] ?? null) ? $incoming[$type] : [];

                    $body = data_get($incoming, 'text.body')
                        ?? data_get($incoming, 'image.caption')
                        ?? data_get($incoming, 'video.caption')
                        ?? data_get($incoming, 'document.caption');

                    $message = $conversation->messages()->create([
                        'provider_message_id' => $providerId,
                        'direction' => 'inbound',
                        'type' => $type,
                        'body' => $body,
                        'media_id' => $mediaObject['id'] ?? null,
                        'media_mime_type' => $mediaObject['mime_type'] ?? null,
                        'media_sha256' => $mediaObject['sha256'] ?? null,
                        'media_filename' => $mediaObject['filename'] ?? null,
                        'status' => 'received',
                        'context' => $incoming['context'] ?? null,
                        'payload' => $incoming,
                        'sent_at' => now(),
                    ]);

                    $conversation->unread_count = ((int) $conversation->unread_count) + 1;
                    $conversation->last_message_at = now();
                    $conversation->last_message_preview = Str::limit((string) ($body ?? '[' . $type . ']'), 500);

                    if ($conversation->status === 'resolved') {
                        $conversation->status = 'open';
                    }

                    $conversation->save();

                    $contactService = app(ContactService::class);
                    $contact = $contactService->syncInbound($account, $conversation, $message, $phone, $profileName);
                    $contactService->createLeadIfNoOpenLead($contact, $message);

                    return [
                        'conversation_id' => (int) $conversation->id,
                        'message_id' => (int) $message->id,
                        'message_data' => [
                            'id' => $message->id,
                            'conversation_id' => $conversation->id,
                            'direction' => $message->direction,
                            'type' => $message->type,
                            'body' => $message->body,
                            'media_id' => $message->media_id,
                            'status' => $message->status,
                            'created_at' => $message->created_at?->toISOString(),
                        ],
                        'conversation_data' => [
                            'id' => $conversation->id,
                            'tenant_id' => $conversation->tenant_id,
                            'customer_phone' => $conversation->customer_phone,
                            'customer_name' => $conversation->customer_name,
                            'status' => $conversation->status,
                            'unread_count' => $conversation->unread_count,
                            'last_message_at' => $conversation->last_message_at?->toISOString(),
                            'last_message_preview' => $conversation->last_message_preview,
                            'is_human_takeover' => $conversation->is_human_takeover,
                        ],
                    ];
                }, 3);

                if ($result) {
                    MessageReceived::dispatch(
                        conversationId: $result['conversation_id'],
                        messageId: $result['message_id'],
                        messageData: $result['message_data']
                    );

                    try {
                        $convModel = Conversation::find($result['conversation_id']);
                        $msgModel = WhatsAppMessage::find($result['message_id']);
                        if ($convModel && $msgModel) {
                            app(AiAssistantService::class)->createReplyDraft($convModel, $msgModel);
                        }
                    } catch (Throwable $aiEx) {
                        Log::error('AI reply draft failed in webhook job: ' . $aiEx->getMessage());
                    }

                    ConversationUpdated::dispatch(
                        conversationId: $result['conversation_id'],
                        conversationData: $result['conversation_data']
                    );
                }
            }

            // 3. Process outbound statuses
            foreach (($value['statuses'] ?? []) as $status) {
                $providerId = $status['id'] ?? null;
                $providerStatus = $status['status'] ?? null;

                if (! $providerId || ! $providerStatus) {
                    continue;
                }

                $message = WhatsAppMessage::query()
                    ->where('provider_message_id', $providerId)
                    ->where('direction', 'outbound')
                    ->first();

                if ($message) {
                    $message->update([
                        'status' => $providerStatus,
                        'context' => ['last_status_event' => $status],
                    ]);

                    if ($message->conversation) {
                        ConversationUpdated::dispatch(
                            conversationId: (int) $message->conversation->id,
                            conversationData: [
                                'id' => $message->conversation->id,
                                'tenant_id' => $message->conversation->tenant_id,
                                'status' => $message->conversation->status,
                                'unread_count' => $message->conversation->unread_count,
                                'last_message_at' => $message->conversation->last_message_at?->toISOString(),
                                'last_message_preview' => $message->conversation->last_message_preview,
                                'latest_message_status' => $providerStatus,
                                'latest_message_id' => $message->id,
                            ]
                        );
                    }
                }
            }

            // 4. Mark event as successfully processed
            $event->update([
                'processing_status' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $event->update([
                'processing_status' => 'failed',
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }
    }
}
