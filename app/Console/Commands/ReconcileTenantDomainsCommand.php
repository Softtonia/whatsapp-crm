<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionTlsCertificateJob;
use App\Models\TenantCustomDomain;
use App\Services\Contracts\TlsCertificateProviderInterface;
use Illuminate\Console\Command;

class ReconcileTenantDomainsCommand extends Command
{
    protected $signature = 'tenants:domains-reconcile';
    protected $description = 'Reconcile pending TLS provisioning and monitor custom domain certificates';

    public function handle(TlsCertificateProviderInterface $tlsProvider): int
    {
        $this->info('Starting custom domain TLS reconciliation...');

        // 1. Reconcile verified domains where TLS is pending or failed
        $pendingDomains = TenantCustomDomain::query()
            ->where('verification_status', 'verified')
            ->whereIn('tls_status', ['pending', 'failed'])
            ->get();

        $this->info("Found {$pendingDomains->count()} verified domains with pending/failed TLS.");

        foreach ($pendingDomains as $domain) {
            $this->line("Dispatching TLS provisioning for {$domain->hostname} (ID: {$domain->id})");
            ProvisionTlsCertificateJob::dispatch($domain);
        }

        // 2. Monitor ready domains for certificate expiry
        $readyDomains = TenantCustomDomain::query()
            ->where('verification_status', 'verified')
            ->where('tls_status', 'ready')
            ->get();

        $renewCount = 0;
        foreach ($readyDomains as $domain) {
            $metadata = $domain->tls_metadata ?? [];
            if (! empty($metadata['expires_at'])) {
                $expiresAt = \Carbon\Carbon::parse($metadata['expires_at']);
                // If certificate expires within 30 days, trigger renewal/provisioning
                if ($expiresAt->isPast() || $expiresAt->diffInDays(now()) < 30) {
                    $this->warn("Certificate for {$domain->hostname} expires on {$expiresAt->toDateString()}. Dispatching renewal.");
                    ProvisionTlsCertificateJob::dispatch($domain);
                    $renewCount++;
                }
            }
        }

        $this->info("Reconciliation complete. {$renewCount} certificate renewals dispatched.");

        return self::SUCCESS;
    }
}
