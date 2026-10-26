<?php

namespace App\Services;

use App\Models\TenantWhatsAppAccount;
use App\Models\Contact;
use App\Models\ContactInteraction;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadPipelineStage;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactService
{
    /**
     * Normalize a WhatsApp phone number to digits only.
     * Do not guess or append a country code.
     */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone)) ?? '';
    }

    /**
     * Create/update the contact and link its conversation.
     * Call this inside the webhook's existing DB transaction.
     */
    public function syncInbound(
        TenantWhatsAppAccount $account,
        Conversation $conversation,
        WhatsAppMessage $message,
        string $phone,
        ?string $profileName = null
    ): Contact {
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedPhone === '') {
            throw new \InvalidArgumentException(
                'A valid WhatsApp phone number is required.'
            );
        }

        return DB::transaction(function () use (
            $account,
            $conversation,
            $message,
            $phone,
            $profileName,
            $normalizedPhone
        ) {
            $contact = Contact::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('normalized_phone', $normalizedPhone)
                ->lockForUpdate()
                ->first();

            if (! $contact) {
                $contact = Contact::create([
                    'tenant_id' => $account->tenant_id,
                    'name' => $profileName,
                    'phone' => $phone,
                    'normalized_phone' => $normalizedPhone,
                    'source' => 'whatsapp',
                    'tags' => [],
                    'custom_fields' => [],
                ]);
            } else {
                $updates = [];

                if (
                    $profileName !== null
                    && trim($profileName) !== ''
                ) {
                    $updates['name'] = trim($profileName);
                }

                $updates['phone'] = $phone;

                if (empty($contact->source)) {
                    $updates['source'] = 'whatsapp';
                }

                if ($updates !== []) {
                    $contact->fill($updates);
                    $contact->save();
                }
            }

            $conversation->contact_id = $contact->id;

            if (
                $profileName !== null
                && trim($profileName) !== ''
            ) {
                $conversation->customer_name = trim($profileName);
            }

            $conversation->save();

            // One interaction per WhatsApp message.
            ContactInteraction::firstOrCreate(
                [
                    'whatsapp_message_id' => $message->id,
                ],
                [
                    'contact_id' => $contact->id,
                    'conversation_id' => $conversation->id,
                    'type' => 'whatsapp_inbound',
                    'summary' => $message->body
                        ?: 'Incoming WhatsApp '
                            . $message->type
                            . ' message',
                    'metadata' => [
                        'provider_message_id' =>
                            $message->provider_message_id,
                        'message_type' => $message->type,
                    ],
                    'occurred_at' => $message->sent_at ?? now(),
                ]
            );

            return $contact;
        }, 3);
    }

    /**
     * Create a Lead only when this Contact has no open Lead
     * in the same tenant.
     *
     * Returns the new Lead, or null if an open Lead already exists.
     */
    public function createLeadIfNoOpenLead(
        Contact $contact,
        WhatsAppMessage $message
    ): ?Lead {
        return DB::transaction(function () use (
            $contact,
            $message
        ) {
            // Serialize Lead checks for this Contact.
            $lockedContact = Contact::query()
                ->whereKey($contact->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingLead = Lead::query()
                ->where('tenant_id', $lockedContact->tenant_id)
                ->where('contact_id', $lockedContact->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existingLead) {
                return null;
            }

            // Use this tenant's first pipeline stage, if configured.
            $stage = LeadPipelineStage::query()
                ->where('tenant_id', $lockedContact->tenant_id)
                ->orderBy('position')
                ->first();

            $messageText = trim((string) $message->body);

            $title = $messageText !== ''
                ? Str::limit($messageText, 150)
                : 'WhatsApp inquiry - '
                    . (
                        $lockedContact->name
                        ?: $lockedContact->phone
                    );

            return Lead::create([
                'tenant_id' => $lockedContact->tenant_id,
                'contact_id' => $lockedContact->id,
                'owner_id' => null,
                'pipeline_stage_id' => $stage?->id,
                'title' => $title,
                'status' => 'open',
                'priority' => 'medium',
            ]);
        }, 3);
    }

    /**
     * Manually create/update a Contact with normalized phone.
     */
    public function saveContact(
        int $tenantId,
        array $data,
        ?Contact $contact = null
    ): Contact {
        $phone = (string) ($data['phone'] ?? '');
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedPhone === '') {
            throw new \InvalidArgumentException(
                'A valid phone number is required.'
            );
        }

        $duplicate = Contact::query()
            ->where('tenant_id', $tenantId)
            ->where('normalized_phone', $normalizedPhone)
            ->when(
                $contact,
                fn ($query) => $query->where(
                    'id',
                    '!=',
                    $contact->id
                )
            )
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException(
                'A contact with this phone number already exists '
                . 'for this tenant.'
            );
        }

        $attributes = [
            'tenant_id' => $tenantId,
            'name' => $data['name'] ?? null,
            'phone' => $phone,
            'normalized_phone' => $normalizedPhone,
            'email' => $data['email'] ?? null,
            'company' => $data['company'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'tags' => $data['tags'] ?? [],
            'custom_fields' => $data['custom_fields'] ?? [],
        ];

        if ($contact) {
            $contact->fill($attributes);
            $contact->save();

            return $contact->refresh();
        }

        // Enforce contacts limit on creation
        app(\App\Services\SubscriptionUsageService::class)->assertCanUse(
            $tenantId,
            'contacts',
            1
        );

        return Contact::create($attributes);
    }
}
