<?php

namespace App\Services;

use App\Enums\CampaignEvent;
use App\Enums\CampaignSendType;
use App\Models\Campaign;
use App\Notifications\CampaignEmailNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CampaignEmailService
{
    public function __construct(
        protected EmailTemplateRenderer $renderer
    ) {}

    /**
     * Trigger email sending for a registered campaign event.
     *
     * @param CampaignEvent|string $event
     * @param mixed $notifiable Model or collection/array of models implementing Notifiable
     * @param array<string, string> $placeholders
     */
    public function triggerEvent($event, $notifiable, array $placeholders = []): void
    {
        if ($event instanceof CampaignEvent) {
            $event = $event->value;
        }

        // Only find active campaigns configured for triggers with this event
        $campaigns = Campaign::with('emailTemplate')
            ->where('is_active', true)
            ->where('send_type', CampaignSendType::TRIGGER->value)
            ->where('event', $event)
            ->get();

        foreach ($campaigns as $campaign) {
            $template = $campaign->emailTemplate;
            if (! $template || ! $template->is_active) {
                continue;
            }

            try {
                $subject = $this->renderer->render($template->subject, $placeholders, $template->key);
                $body = $this->renderer->render($template->body, $placeholders, $template->key);
                $type = $template->type?->value ?? (string) $template->type ?: 'html';

                $notification = new CampaignEmailNotification($subject, $body, $type);

                if (is_iterable($notifiable)) {
                    Notification::send($notifiable, $notification);
                } else {
                    $notifiable->notify($notification);
                }
            } catch (\Throwable $e) {
                // Log failure without exposing sensitive data (e.g. OTPs or tokens)
                Log::error('Failed to dispatch campaign trigger email', [
                    'campaign_id' => $campaign->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
