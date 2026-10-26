<?php

namespace App\Actions\Admin\Campaign;

use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;

class CreateCampaignAction
{
    public function execute(array $data, ?int $tenantId = null): Campaign
    {
        $tenantId = $tenantId ?? request()->input('tenant_id', request()->user()?->tenant_id ?? request()->user()?->memberships()?->value('tenant_id'));
        $ledger = null;

        if ($tenantId) {
            $ledger = app(\App\Services\QuotaEnforcementService::class)->reserveQuota(
                tenantId: (int) $tenantId,
                metric: \App\Services\QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS,
                quantity: 1,
                idempotencyKey: 'campaign_create_' . ($data['name'] ?? '') . '_' . now()->timestamp,
                sourceType: 'campaign',
                sourceId: null
            );
        }

        try {
            $campaign = Campaign::create($data);

            if ($campaign->send_type->value === 'now' && $campaign->is_active) {
                $campaign->update([
                    'is_active' => false,
                    'dispatched_at' => now(),
                ]);
                DispatchCampaignJob::dispatch($campaign);
            }

            if ($ledger) {
                $ledger->update(['source_id' => (string) $campaign->id]);
            }

            return $campaign;
        } catch (\Throwable $e) {
            if ($ledger) {
                app(\App\Services\QuotaEnforcementService::class)->releaseReservation($ledger);
            }
            throw $e;
        }
    }
}
