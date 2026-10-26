<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadFollowUpReminder extends Notification
{
    use Queueable;

    public function __construct(
        public Lead $lead
    ) {}

    public function via(object $notifiable): array
    {
        // Store reminders in Laravel's notifications table.
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_follow_up',
            'lead_id' => $this->lead->id,
            'tenant_id' => $this->lead->tenant_id ?? $this->lead->business_id,
            'business_id' => $this->lead->tenant_id ?? $this->lead->business_id,
            'contact_id' => $this->lead->contact_id,
            'title' => 'Lead follow-up is due',
            'message' => 'Follow up with '
                . ($this->lead->contact?->name
                    ?: $this->lead->contact?->phone
                    ?: $this->lead->title),
            'follow_up_at' => $this->lead->follow_up_at?->toISOString(),
            'url' => '/leads/' . $this->lead->id,
        ];
    }
}
