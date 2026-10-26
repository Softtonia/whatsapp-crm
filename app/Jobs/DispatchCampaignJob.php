<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\User;
use App\Notifications\CampaignEmailNotification;
use App\Services\EmailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public Campaign $campaign
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmailTemplateRenderer $renderer): void
    {
        // For trigger campaigns, if inactive, skip
        if ($this->campaign->send_type->value === 'trigger' && ! $this->campaign->is_active) {
            return;
        }

        $template = $this->campaign->emailTemplate;
        if (! $template || ! $template->is_active) {
            return;
        }

        // Query targeted users
        $query = User::query();
        if (! empty($this->campaign->target_users)) {
            $query->whereIn('id', $this->campaign->target_users);
        }

        $users = $query->get();
        $type = $template->type?->value ?? (string) $template->type ?: 'html';

        foreach ($users as $user) {
            if (empty($user->email)) {
                Log::warning('Skipping campaign email recipient: User has no email address', [
                    'campaign_id' => $this->campaign->id,
                    'user_id' => $user->id,
                ]);
                continue;
            }

            try {
                $placeholders = [
                    'UserName' => $user->full_name ?: $user->name ?: 'User',
                    'AdminName' => $user->full_name ?: $user->name ?: 'Admin',
                    'name' => $user->full_name ?: $user->name ?: 'User',
                    'email' => $user->email,
                    'phone' => $user->phone_number,
                ];

                $subject = $renderer->render($template->subject, $placeholders, $template->key);
                $body = $renderer->render($template->body, $placeholders, $template->key);

                $user->notifyNow(
                    new CampaignEmailNotification($subject, $body, $type)
                );
            } catch (\Throwable $e) {
                Log::error('Failed to send campaign email to recipient', [
                    'campaign_id' => $this->campaign->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
