<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTenantOffboardingJob;
use App\Models\TenantOffboardingJob;
use Illuminate\Console\Command;

class DispatchScheduledTenantOffboarding extends Command
{
    protected $signature = 'tenants:dispatch-scheduled-offboarding';

    protected $description = 'Dispatch approved tenant offboarding requests that are due';

    public function handle(): int
    {
        $dispatched = 0;

        TenantOffboardingJob::query()
            ->where('status', 'approved')
            ->where('purge_status', 'not_requested')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$dispatched): void {
                foreach ($jobs as $job) {
                    // Defense in depth: never dispatch purge requests.
                    if (
                        ($job->options['operation'] ?? null) === 'purge_request'
                        || ($job->purge_status ?? 'not_requested') !== 'not_requested'
                    ) {
                        continue;
                    }

                    ProcessTenantOffboardingJob::dispatch($job->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} due offboarding job(s).");

        return self::SUCCESS;
    }
}
