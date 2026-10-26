<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\TenantWhatsAppAccount;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppCloudApiService
{
    public function send(
        Conversation $conversation,
        User $sender,
        array $data
    ): WhatsAppMessage {
        $conversation->loadMissing('whatsappAccount');

        // Check subscription limits before contacting WhatsApp API
        app(SubscriptionUsageService::class)->assertCanUse(
            (int) $conversation->tenant_id,
            'whatsapp_messages',
            1
        );

        $account = $conversation->whatsappAccount;

        if (
            ! $account ||
            $account->status !== 'active' ||
            ! $account->access_token ||
            ! $account->phone_number_id
        ) {
            throw new RuntimeException(
                'No active WhatsApp account with valid credentials is configured for this conversation.'
            );
        }

        $type = $data['type'] ?? 'text';

        if (! in_array(
            $type,
            ['text', 'image', 'document', 'video', 'audio'],
            true
        )) {
            throw new RuntimeException('Unsupported message type.');
        }

        if ($type === 'text') {
            $body = trim((string) ($data['body'] ?? ''));

            if ($body === '') {
                throw new RuntimeException(
                    'Text message body cannot be empty.'
                );
            }

            $messageContent = [
                'preview_url' => false,
                'body' => $body,
            ];
        } else {
            $messageContent = $this->mediaPayload($type, $data);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $conversation->customer_phone,
            'type' => $type,
            $type => $messageContent,
        ];

        // Save before calling Meta so failures can be recorded.
        $message = $conversation->messages()->create([
            'sent_by_user_id' => $sender->id,
            'direction' => 'outbound',
            'type' => $type,
            'body' => $data['body'] ?? $data['caption'] ?? null,
            'media_id' => $data['media_id'] ?? null,
            'media_mime_type' => $data['mime_type'] ?? null,
            'media_filename' => $data['filename'] ?? null,
            'status' => 'pending',
            'payload' => $payload,
        ]);

        $version = config('services.whatsapp.graph_version', 'v23.0');

        try {
            $response = Http::withToken($account->access_token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(25)
                ->post(
                    "https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages",
                    $payload
                );
        } catch (ConnectionException $exception) {
            // The provider may have received the request before a timeout.
            // Do not retry automatically because this could send duplicates.
            $message->update([
                'status' => 'failed',
                'context' => [
                    'error' => 'Connection to WhatsApp Cloud API failed or timed out.',
                    'delivery_unknown' => true,
                ],
            ]);

            report($exception);

            throw new RuntimeException(
                'WhatsApp request timed out or the API could not be reached. Check message status before retrying.'
            );
        }

        if (! $response->successful()) {
            $providerError = $response->json('error');

            $message->update([
                'status' => 'failed',
                'context' => [
                    'provider_error' => $providerError
                        ?? ['message' => 'Unknown provider error'],
                    'http_status' => $response->status(),
                ],
            ]);

            $errorMessage = data_get(
                $providerError,
                'message',
                'Unknown provider error'
            );

            throw new RuntimeException(
                'WhatsApp rejected the message: '
                . Str::limit((string) $errorMessage, 220)
            );
        }

        $providerId = $response->json('messages.0.id');

        if (! is_string($providerId) || $providerId === '') {
            $message->update([
                'status' => 'failed',
                'context' => [
                    'provider_response' => $response->json(),
                    'error' => 'Provider response did not contain a message ID.',
                    'delivery_unknown' => true,
                ],
            ]);

            throw new RuntimeException(
                'WhatsApp returned a successful response without a message ID. Verify the provider response before retrying.'
            );
        }

        $message->update([
            'provider_message_id' => $providerId,
            'status' => 'sent',
            'sent_at' => now(),
            'context' => [
                'provider_response' => $response->json(),
            ],
        ]);

        $preview = $data['body']
            ?? $data['caption']
            ?? '[' . $type . ']';

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => Str::limit(
                (string) $preview,
                500
            ),
        ]);

        // Record successful WhatsApp message usage atomically
        app(SubscriptionUsageService::class)->recordUsage(
            (int) $conversation->tenant_id,
            'whatsapp_messages',
            1
        );

        return $message->refresh();
    }

    private function mediaPayload(string $type, array $data): array
    {
        $media = [];

        if (! empty($data['media_id'])) {
            $media['id'] = $data['media_id'];
        } elseif (! empty($data['link'])) {
            $url = $data['link'];
            $host = parse_url($url, PHP_URL_HOST);

            if (
                ! is_string($host) ||
                parse_url($url, PHP_URL_SCHEME) !== 'https'
            ) {
                throw new RuntimeException(
                    'Media must use a valid HTTPS URL.'
                );
            }

            $media['link'] = $url;
        } else {
            throw new RuntimeException(
                'Provide media_id or a publicly accessible HTTPS link for media messages.'
            );
        }

        if (
            ! empty($data['caption']) &&
            in_array($type, ['image', 'video', 'document'], true)
        ) {
            $media['caption'] = $data['caption'];
        }

        if ($type === 'document' && ! empty($data['filename'])) {
            $media['filename'] = $data['filename'];
        }

        return $media;
    }

    public function downloadInboundMedia(WhatsAppMessage $message): array
    {
        $message->loadMissing('conversation.whatsappAccount');

        $conversation = $message->conversation;
        $account = $conversation?->whatsappAccount;

        if (
            ! $account ||
            $account->status !== 'active' ||
            ! $account->access_token ||
            ! $message->media_id
        ) {
            throw new RuntimeException(
                'Media is not available for this message.'
            );
        }

        $version = config('services.whatsapp.graph_version', 'v23.0');

        try {
            $metadata = Http::withToken($account->access_token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get(
                    "https://graph.facebook.com/{$version}/{$message->media_id}"
                );
        } catch (ConnectionException $exception) {
            report($exception);

            throw new RuntimeException(
                'Could not connect to WhatsApp to retrieve media metadata.'
            );
        }

        if (
            ! $metadata->successful() ||
            ! is_string($metadata->json('url')) ||
            $metadata->json('url') === ''
        ) {
            throw new RuntimeException(
                'Could not retrieve WhatsApp media metadata.'
            );
        }

        $url = $metadata->json('url');
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        $allowedHost = is_string($host) && (
            Str::endsWith(strtolower($host), '.fbsbx.com') ||
            Str::endsWith(strtolower($host), '.fbcdn.net')
        );

        if ($scheme !== 'https' || ! $allowedHost) {
            throw new RuntimeException(
                'WhatsApp returned an unexpected media URL.'
            );
        }

        try {
            $download = Http::withToken($account->access_token)
                ->connectTimeout(10)
                ->timeout(35)
                ->get($url);
        } catch (ConnectionException $exception) {
            report($exception);

            throw new RuntimeException(
                'Could not connect to download WhatsApp media.'
            );
        }

        if (! $download->successful()) {
            throw new RuntimeException(
                'Could not download WhatsApp media.'
            );
        }

        return [
            'bytes' => $download->body(),
            'mime_type' => $message->media_mime_type
                ?: $metadata->json('mime_type', 'application/octet-stream'),
            'filename' => $message->media_filename,
        ];
    }

    /**
     * Synchronize WABA and Phone Number details with Meta Graph API.
     */
    public function syncAccountDetails(TenantWhatsAppAccount $account): array
    {
        if (! $account->access_token || ! $account->phone_number_id) {
            throw new RuntimeException('WhatsApp account is missing access token or phone number ID.');
        }

        $version = config('services.whatsapp.graph_version', 'v23.0');

        try {
            // Fetch phone number details from Meta Graph API
            $phoneRes = Http::withToken($account->access_token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get("https://graph.facebook.com/{$version}/{$account->phone_number_id}?fields=display_phone_number,verified_name,quality_rating,code_verification_status");

            if (! $phoneRes->successful()) {
                $err = $phoneRes->json('error.message') ?? 'Failed to fetch phone number details from Meta.';
                $account->update([
                    'last_sync_error' => Str::limit($err, 255),
                    'last_synced_at' => now(),
                ]);
                throw new RuntimeException($err);
            }

            $phoneData = $phoneRes->json();

            // Fetch WABA business name if waba_id is available
            $businessName = $phoneData['verified_name'] ?? $account->business_name;
            if ($account->waba_id) {
                $wabaRes = Http::withToken($account->access_token)
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(20)
                    ->get("https://graph.facebook.com/{$version}/{$account->waba_id}?fields=name,timezone_id");

                if ($wabaRes->successful() && $wabaRes->json('name')) {
                    $businessName = $wabaRes->json('name');
                }
            }

            $account->update([
                'display_phone_number' => $phoneData['display_phone_number'] ?? $account->display_phone_number,
                'business_name' => $businessName,
                'quality_rating' => $phoneData['quality_rating'] ?? $account->quality_rating,
                'last_synced_at' => now(),
                'last_sync_error' => null,
            ]);

            return [
                'success' => true,
                'account' => $account->fresh(),
            ];
        } catch (ConnectionException $e) {
            $account->update([
                'last_synced_at' => now(),
                'last_sync_error' => 'Meta API connection timed out.',
            ]);
            throw new RuntimeException('Meta API connection timed out.');
        }
    }
}
