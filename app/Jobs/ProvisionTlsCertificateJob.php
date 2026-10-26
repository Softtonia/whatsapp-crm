<?php

namespace App\Jobs;

use App\Models\TenantCustomDomain;
use App\Services\CustomDomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionTlsCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public TenantCustomDomain $domain
    ) {}

    public function handle(CustomDomainService $domainService): void
    {
        $domain = $this->domain->fresh();

        if (! $domain || ! $domain->isVerified()) {
            Log::info("Skipping TLS provisioning for unverified or missing domain {$this->domain->id}");
            return;
        }

        if ($domain->tls_status === 'ready') {
            Log::info("TLS certificate already ready for domain {$domain->hostname}");
            return;
        }

        try {
            $domainService->provisionTls($domain);
        } catch (\Throwable $e) {
            Log::error("Failed to provision TLS for domain {$domain->hostname}: {$e->getMessage()}");
            throw $e;
        }
    }
}
