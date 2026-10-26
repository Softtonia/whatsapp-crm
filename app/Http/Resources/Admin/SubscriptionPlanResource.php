<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'description' => $this->description,

            'price' => (float) $this->price,
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle,

            'is_active' => (bool) $this->is_active,
            'status' => $this->status,
            'plan_type' => $this->plan_type,
            'visibility' => $this->visibility,
            'available_for_signup' => (bool) $this->available_for_signup,

            'trial_days' => $this->trial_days,
            'setup_fee' => $this->setup_fee,
            'setup_fee_required' => (bool) $this->setup_fee_required,
            'sort_order' => $this->sort_order,
            'published_at' => $this->published_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),

            'features' => $this->features ?? [],

            'limits' => [
                'max_whatsapp_messages' => $this->max_whatsapp_messages,
                'max_ai_replies' => $this->max_ai_replies,
                'max_team_members' => $this->max_team_members,
                'max_whatsapp_accounts' => $this->max_whatsapp_accounts,
                'max_contacts' => $this->max_contacts,
            ],

            'subscribers_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
