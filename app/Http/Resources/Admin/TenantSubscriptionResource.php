<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id ?? $this->business_id,
            'business_id' => $this->tenant_id ?? $this->business_id,
            'subscription_plan_id' => $this->subscription_plan_id,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'trial_ends_at' => $this->trial_ends_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'notes' => $this->notes,
            'plan' => new SubscriptionPlanResource($this->whenLoaded('plan')),
            'tenant' => $this->whenLoaded('tenant', fn() => [
                'id' => $this->tenant->id,
                'company_name' => $this->tenant->company_name ?? $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
            'business' => $this->whenLoaded('tenant', fn() => [
                'id' => $this->tenant->id,
                'company_name' => $this->tenant->company_name ?? $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
