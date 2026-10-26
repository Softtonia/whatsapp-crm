<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantHealthCheckService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunTenantHealthChecks extends Command
{
    protected $signature = 'tenants:health-check';

    protected $description = 'Run health checks for all tenants';

    public function handle(TenantHealthCheckService $service): int
    {
        $checked = 0;
        $failed = 0;

        Tenant::query()->chunkById(100, function ($tenants) use (
            $service,
            &$checked,
            &$failed
        ) {
            foreach ($tenants as $tenant) {
                try {
                    $result = $service->runForTenant($tenant);

                    $this->line(
                        "Tenant {$tenant->id}: {$result['overall_status']}"
                    );

                    $checked++;
                } catch (Throwable $e) {
                    report($e);

                    $this->error(
                        "Tenant {$tenant->id}: health check failed."
                    );

                    $failed++;
                }
            }
        });

        Cache::put(
            'tenant_health_scheduler_last_run',
            now()->toIso8601String(),
            now()->addMinutes(15)
        );

        $this->info("Checked: {$checked}; failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}