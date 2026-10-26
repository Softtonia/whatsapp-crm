<?php

namespace App\Http\Resources\Admin;

use App\Models\Conversation;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $owner = null;

        if ($this->relationLoaded('memberships')) {
            $ownerMembership = $this->memberships->first(
                fn($membership) =>
                in_array($membership->role, ['owner', 'super_admin'], true)
            );

            if (
                $ownerMembership
                && $ownerMembership->relationLoaded('user')
                && $ownerMembership->user
            ) {
                $owner = [
                    'id' => $ownerMembership->user->id,
                    'name' => $ownerMembership->user->full_name,
                    'email' => $ownerMembership->user->email,
                    'phone' => $ownerMembership->user->phone_number,
                ];
            }
        }

        $usage = null;

        if (
            $this->relationLoaded('conversations')
            || $this->relationLoaded('whatsappAccounts')
        ) {
            $conversationQuery = Conversation::query()
                ->where('tenant_id', $this->id);

            $conversationIds = (clone $conversationQuery)->pluck('id');

            $totalMessages = 0;
            $inboundMessages = 0;
            $outboundMessages = 0;

            if ($conversationIds->isNotEmpty()) {
                $messages = WhatsAppMessage::query()
                    ->whereIn('conversation_id', $conversationIds);

                $totalMessages = (clone $messages)->count();

                $inboundMessages = (clone $messages)
                    ->where('direction', 'inbound')
                    ->count();

                $outboundMessages = (clone $messages)
                    ->where('direction', 'outbound')
                    ->count();
            }

            $usage = [
                'total_conversations' => $conversationQuery->count(),
                'total_messages' => $totalMessages,
                'inbound_messages' => $inboundMessages,
                'outbound_messages' => $outboundMessages,
            ];
        }

        return [
            'id' => $this->id,
            'company_name' => $this->company_name ?? $this->name,
            'name' => $this->company_name ?? $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'onboarding_status' => $this->onboarding_status ?? 'not_started',
            'onboarding_completed_at' =>
            $this->onboarding_completed_at?->toISOString(),
            'suspend_reason' => $this->suspend_reason,

            // Business profile fields
            'logo' => $this->logo,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'address' => $this->address,
            'country' => $this->country,
            'currency' => $this->currency,
            'timezone' => $this->timezone,

            'owner' => $owner,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Related data
            'members_count' => $this->whenCounted('users'),

            'members' => $this->whenLoaded(
                'memberships',
                function () {
                    return $this->memberships->map(
                        function ($membership) {
                            $user = $membership->relationLoaded('user')
                                ? $membership->user
                                : null;

                            return [
                                'id' => $membership->id,
                                'user_id' => $membership->user_id,
                                'role' => $membership->role,
                                'status' => $membership->status,

                                'user' => $user ? [
                                    'id' => $user->id,
                                    'first_name' => $user->first_name,
                                    'last_name' => $user->last_name,
                                    'full_name' => $user->full_name,
                                    'email' => $user->email,
                                    'phone_number' => $user->phone_number,
                                ] : null,

                                'created_at' => $membership->created_at
                                    ?->toISOString(),
                            ];
                        }
                    );
                }
            ),

            'whatsapp_accounts' => $this->whenLoaded(
                'whatsappAccounts',
                function () {
                    return $this->whatsappAccounts->map(
                        function ($account) {
                            return [
                                'id' => $account->id,
                                'phone_number_id' => $account->phone_number_id,
                                'waba_id' => $account->waba_id,
                                'display_phone_number' => $account->display_phone_number,
                                'status' => $account->status,
                                'created_at' => $account->created_at
                                    ?->toISOString(),
                            ];
                        }
                    );
                }
            ),

            'usage' => $this->when($usage !== null, $usage),

            'support' => [
                'help_url' => config('app.url') . '/support',
                'documentation_url' => config('app.url') . '/docs',
                'contact_email' => config(
                    'mail.from.address',
                    'support@softtonia.com'
                ),
            ],
        ];
    }
}
