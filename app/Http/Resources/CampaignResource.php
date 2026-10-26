<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email_template_id' => $this->email_template_id,
            'send_type' => $this->send_type?->value ?? (string) $this->send_type,
            'event' => $this->event?->value ?? (string) $this->event,
            'target_users' => $this->target_users,
            'scheduled_at' => $this->scheduled_at?->format('Y-m-d g:i A'),
            'scheduled_at_raw' => $this->scheduled_at?->format('Y-m-d H:i:s'),
            'dispatched_at' => $this->dispatched_at?->format('Y-m-d g:i A'),
            'dispatched_at_raw' => $this->dispatched_at?->format('Y-m-d H:i:s'),
            'status' => $this->computeStatus(),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'email_template' => new EmailTemplateResource($this->whenLoaded('emailTemplate')),
        ];
    }

    protected function computeStatus(): string
    {
        if ($this->dispatched_at !== null) {
            return 'dispatched';
        }

        if ($this->send_type?->value === 'schedule') {
            if (! $this->is_active) {
                return 'inactive';
            }
            if ($this->scheduled_at && $this->scheduled_at->isFuture()) {
                return 'scheduled';
            }
            return 'pending';
        }

        if ($this->send_type?->value === 'trigger') {
            return $this->is_active ? 'active' : 'inactive';
        }

        return $this->is_active ? 'pending' : 'inactive';
    }
}
