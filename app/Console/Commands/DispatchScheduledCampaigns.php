<?php

namespace App\Console\Commands;

use App\Enums\CampaignSendType;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchScheduledCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaigns:dispatch-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch scheduled campaigns that are due based on local application time';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();
        $dispatchedCount = 0;

        $dueCampaignIds = Campaign::query()
            ->where('send_type', CampaignSendType::SCHEDULE->value)
            ->where('is_active', true)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->whereNull('dispatched_at')
            ->pluck('id');

        if ($dueCampaignIds->isEmpty()) {
            $this->info('No scheduled campaigns due for dispatch.');
            return self::SUCCESS;
        }

        foreach ($dueCampaignIds as $campaignId) {
            // Atomic claim: update dispatched_at only if it is still null and active
            $claimed = DB::table('campaigns')
                ->where('id', $campaignId)
                ->where('send_type', CampaignSendType::SCHEDULE->value)
                ->where('is_active', true)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', $now)
                ->whereNull('dispatched_at')
                ->update([
                    'dispatched_at' => $now,
                    'is_active' => false,
                    'updated_at' => $now,
                ]);

            if ($claimed === 0) {
                // Already claimed by another worker or inactivated
                continue;
            }

            $campaign = Campaign::with('emailTemplate')->find($campaignId);

            if (! $campaign) {
                continue;
            }

            try {
                DispatchCampaignJob::dispatch($campaign);
                $dispatchedCount++;

                $this->info("Dispatched scheduled campaign [ID: {$campaign->id}, Name: '{$campaign->name}']");
                Log::info("Dispatched scheduled campaign", [
                    'campaign_id' => $campaign->id,
                    'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d H:i:s'),
                    'dispatched_at' => $now->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to enqueue scheduled campaign job", [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Total scheduled campaigns dispatched: {$dispatchedCount}");

        return self::SUCCESS;
    }
}
