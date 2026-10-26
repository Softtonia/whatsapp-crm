<?php

namespace App\Actions\Admin\Campaign;

use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;

class UpdateCampaignAction
{
    public function execute(Campaign $campaign, array $data): Campaign
    {
        $campaign->update($data);

        if ($campaign->send_type->value === 'now' && $campaign->is_active) {
            $campaign->update([
                'is_active' => false,
                'dispatched_at' => now(),
            ]);
            DispatchCampaignJob::dispatch($campaign);
        }

        return $campaign;
    }
}
