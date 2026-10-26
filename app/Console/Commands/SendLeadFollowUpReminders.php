<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Notifications\LeadFollowUpReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendLeadFollowUpReminders extends Command
{
    protected $signature = 'leads:send-follow-up-reminders';

    protected $description = 'Send notifications for due lead follow-ups';

    public function handle(): int
    {
        $now = now();
        $sent = 0;

        Lead::query()
            ->with(['contact', 'owner'])
            ->where('status', 'open')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $now)
            ->chunkById(100, function ($leads) use (&$sent) {
                foreach ($leads as $lead) {
                    // Assigned owner is the intended recipient.
                    $owner = $lead->owner;

                    if (! $owner) {
                        $this->warn(
                            "Lead {$lead->id} skipped: no owner assigned."
                        );

                        continue;
                    }

                    // Prevent repeated reminders for the same lead and
                    // the same follow_up_at value.
                    $followUpKey = $lead->follow_up_at->toISOString();

                    $alreadySent = DB::table('notifications')
                        ->where('notifiable_type', get_class($owner))
                        ->where('notifiable_id', $owner->getKey())
                        ->where('type', LeadFollowUpReminder::class)
                        ->where('data->lead_id', $lead->id)
                        ->where('data->follow_up_at', $followUpKey)
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $owner->notify(
                        new LeadFollowUpReminder($lead)
                    );

                    $sent++;
                }
            });

        $this->info("Follow-up reminders created: {$sent}");

        return self::SUCCESS;
    }
}
