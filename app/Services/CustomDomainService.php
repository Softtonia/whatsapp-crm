<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantCustomDomain;
use App\Models\User;
use App\Services\Contracts\DnsResolverInterface;
use App\Services\Contracts\TlsCertificateProviderInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CustomDomainService
{
    /**
     * Reserved hostnames that cannot be registered.
     */
    public const PROHIBITED_HOSTNAMES = [
        'localhost',
        'api.localhost',
        'crm.local',
        'whatsapp-crm.local',
        '127.0.0.1',
        '0.0.0.0',
        'example.com',
    ];

    public function __construct(
        protected ?DnsResolverInterface $dnsResolver = null,
        protected ?TlsCertificateProviderInterface $tlsProvider = null
    ) {
        $this->dnsResolver = $dnsResolver ?? app(DnsResolverInterface::class);
        $this->tlsProvider = $tlsProvider ?? app(TlsCertificateProviderInterface::class);
    }

    /**
     * Normalize and validate a custom hostname.
     *
     * Supports standard ASCII DNS hostnames.
     * Rejects URLs, paths, ports, wildcards and IP addresses.
     */
    public function normalizeHostname(string $rawHostname): string
    {
        $hostname = trim($rawHostname);

        if ($hostname === '') {
            throw new InvalidArgumentException('Hostname is required.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $hostname)) {
            throw new InvalidArgumentException('Hostnames must not contain a URL scheme.');
        }

        if (
            str_contains($hostname, '/') ||
            str_contains($hostname, '?') ||
            str_contains($hostname, '#') ||
            str_contains($hostname, '@') ||
            str_contains($hostname, '\\')
        ) {
            throw new InvalidArgumentException(
                'Hostnames must not contain paths, queries, credentials, or fragments.'
            );
        }

        if (str_contains($hostname, ':')) {
            throw new InvalidArgumentException('Hostnames must not contain a port.');
        }

        if (str_contains($hostname, '*')) {
            throw new InvalidArgumentException('Wildcard hostnames are not supported.');
        }

        // DNS hostnames are case-insensitive.
        $hostname = strtolower(rtrim($hostname, '.'));

        if ($hostname === '') {
            throw new InvalidArgumentException('The hostname format is invalid.');
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('IP addresses are not permitted as custom domains.');
        }

        /*
         * Validate standard ASCII DNS hostname syntax.
         * Total length: max 253 chars.
         * Labels: 1 to 63 chars, letters/digits/hyphens, no leading/trailing hyphens.
         */
        $pattern = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

        if (! preg_match($pattern, $hostname)) {
            throw new InvalidArgumentException('The hostname format is invalid.');
        }

        if (in_array($hostname, self::PROHIBITED_HOSTNAMES, true)) {
            throw new InvalidArgumentException(
                'The specified hostname is reserved and cannot be registered.'
            );
        }

        return $hostname;
    }

    /**
     * Register a custom domain and generate its DNS TXT challenge.
     *
     * The domain remains inactive until ownership verification,
     * TLS provisioning and routing activation are completed.
     */
    public function registerDomain(
        Tenant $tenant,
        string $hostname,
        ?User $actor = null,
        ?Request $request = null
    ): TenantCustomDomain {
        $normalized = $this->normalizeHostname($hostname);

        if (
            TenantCustomDomain::query()
                ->where('hostname', $normalized)
                ->exists()
        ) {
            throw new InvalidArgumentException(
                "The domain '{$normalized}' is already registered."
            );
        }

        $verificationToken = Str::random(40);
        $verificationHostname = '_whatsappcrm-challenge.'.$normalized;
        $expectedRecordValue = 'whatsapp-crm-verification='.$verificationToken;

        try {
            $domain = TenantCustomDomain::create([
                'tenant_id' => $tenant->id,
                'hostname' => $normalized,
                'verification_status' => 'pending',
                'verification_record_type' => 'TXT',
                'verification_hostname' => $verificationHostname,
                'verification_token' => $verificationToken,
                'expected_record_value' => $expectedRecordValue,
                'verified_at' => null,
                'tls_status' => 'pending',
                'tls_metadata' => null,
                'is_active' => false,
                'last_verification_attempt_at' => null,
                'last_verification_error' => null,
            ]);
        } catch (QueryException $e) {
            if (
                TenantCustomDomain::query()
                    ->where('hostname', $normalized)
                    ->exists()
            ) {
                throw new InvalidArgumentException(
                    "The domain '{$normalized}' is already registered."
                );
            }

            throw $e;
        }

        if ($actor) {
            $this->logActivity(
                tenant: $tenant,
                actor: $actor,
                eventType: 'tenant.domain_registered',
                description: "Registered custom domain {$normalized}.",
                oldValues: null,
                newValues: [
                    'hostname' => $normalized,
                    'verification_status' => 'pending',
                    'tls_status' => 'pending',
                    'is_active' => false,
                ],
                request: $request
            );
        }

        return $domain;
    }

    /**
     * Verify domain ownership using a DNS TXT record.
     *
     * A custom resolver callback may be supplied for ad-hoc tests,
     * otherwise the injected DnsResolverInterface is used.
     */
    public function verifyDomain(
        TenantCustomDomain $domain,
        ?callable $dnsResolver = null,
        ?User $actor = null,
        ?Request $request = null
    ): array {
        $domain->last_verification_attempt_at = now();
        $domain->save();

        $lookupHost = $domain->verification_hostname;
        $expectedValue = trim($domain->expected_record_value);

        try {
            if ($dnsResolver !== null) {
                $txtRecords = $dnsResolver($lookupHost);
            } else {
                $txtRecords = $this->dnsResolver->resolveTxt($lookupHost);
            }

            if (! is_array($txtRecords)) {
                $txtRecords = [];
            }
        } catch (Throwable $e) {
            report($e);

            $domain->last_verification_error = 'DNS lookup failed due to a resolver error.';
            $domain->is_active = false;
            $domain->save();

            return [
                'success' => false,
                'status' => 'pending',
                'message' => 'DNS lookup failed. Please try again later.',
            ];
        }

        $isMatch = false;

        foreach ($txtRecords as $record) {
            if (! is_string($record)) {
                continue;
            }

            if (hash_equals($expectedValue, trim($record))) {
                $isMatch = true;
                break;
            }
        }

        if (! $isMatch) {
            $domain->verification_status = 'failed';
            $domain->is_active = false;
            $domain->last_verification_error =
                'The required DNS TXT verification record was not found or did not match.';
            $domain->save();

            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Domain verification failed. Check the DNS TXT record and try again.',
                'dns_instructions' => [
                    'record_type' => $domain->verification_record_type,
                    'host' => $domain->verification_hostname,
                    'value' => $domain->expected_record_value,
                ],
            ];
        }

        $previousStatus = $domain->verification_status;

        $domain->verification_status = 'verified';
        $domain->verified_at = now();
        $domain->last_verification_error = null;
        // Ownership verified; TLS and routing are not yet confirmed.
        $domain->tls_status = $domain->tls_status === 'ready' ? 'ready' : 'pending';
        $domain->is_active = false;
        $domain->save();

        if ($actor) {
            $this->logActivity(
                tenant: $domain->tenant,
                actor: $actor,
                eventType: 'tenant.domain_verified',
                description: "Verified ownership of custom domain {$domain->hostname}.",
                oldValues: [
                    'verification_status' => $previousStatus,
                ],
                newValues: [
                    'verification_status' => 'verified',
                    'verified_at' => $domain->verified_at?->toISOString(),
                    'tls_status' => $domain->tls_status,
                    'is_active' => false,
                ],
                request: $request
            );
        }

        return [
            'success' => true,
            'status' => 'verified',
            'message' => 'Domain ownership verified successfully. TLS provisioning is still pending.',
            'domain' => $domain->fresh(),
        ];
    }

    /**
     * Request or retry TLS certificate provisioning for a verified domain.
     */
    public function provisionTls(
        TenantCustomDomain $domain,
        ?User $actor = null,
        ?Request $request = null
    ): array {
        if (! $domain->isVerified()) {
            throw new InvalidArgumentException(
                'DNS ownership verification is required before provisioning TLS certificates.'
            );
        }

        $previousTlsStatus = $domain->tls_status;
        $domain->tls_status = 'provisioning';
        $domain->save();

        $result = $this->tlsProvider->provisionCertificate($domain);

        $domain->tls_status = $result['status'] ?? ($result['success'] ? 'ready' : 'pending');
        if (isset($result['metadata'])) {
            $domain->tls_metadata = array_merge($domain->tls_metadata ?? [], $result['metadata']);
        }
        $domain->save();

        if ($actor) {
            $this->logActivity(
                tenant: $domain->tenant,
                actor: $actor,
                eventType: 'tenant.domain_tls_provisioned',
                description: "TLS provisioning status for {$domain->hostname}: {$domain->tls_status}.",
                oldValues: ['tls_status' => $previousTlsStatus],
                newValues: [
                    'tls_status' => $domain->tls_status,
                    'tls_metadata' => $domain->tls_metadata,
                ],
                request: $request
            );
        }

        return $result;
    }

    /**
     * Activate a custom domain.
     * Requires:
     * 1. DNS ownership verified.
     * 2. TLS status ready.
     * 3. Tenant membership and subscription validity checked.
     */
    public function activateDomain(
        TenantCustomDomain $domain,
        ?User $actor = null,
        ?Request $request = null
    ): TenantCustomDomain {
        if (! $domain->isVerified()) {
            throw new InvalidArgumentException(
                'Domain cannot be activated because DNS ownership is not verified.'
            );
        }

        if ($domain->tls_status !== 'ready') {
            throw new InvalidArgumentException(
                'Domain cannot be activated until the TLS certificate is provisioned and ready.'
            );
        }

        DB::transaction(function () use ($domain, $actor, $request) {
            $previousActive = $domain->is_active;
            $domain->is_active = true;
            $domain->save();

            if ($actor) {
                $this->logActivity(
                    tenant: $domain->tenant,
                    actor: $actor,
                    eventType: 'tenant.domain_activated',
                    description: "Activated custom domain {$domain->hostname}.",
                    oldValues: ['is_active' => $previousActive],
                    newValues: ['is_active' => true],
                    request: $request
                );
            }
        });

        return $domain->fresh();
    }

    /**
     * Deactivate a custom domain.
     */
    public function deactivateDomain(
        TenantCustomDomain $domain,
        ?User $actor = null,
        ?Request $request = null
    ): TenantCustomDomain {
        DB::transaction(function () use ($domain, $actor, $request) {
            $previousActive = $domain->is_active;
            $domain->is_active = false;
            $domain->save();

            if ($actor) {
                $this->logActivity(
                    tenant: $domain->tenant,
                    actor: $actor,
                    eventType: 'tenant.domain_deactivated',
                    description: "Deactivated custom domain {$domain->hostname}.",
                    oldValues: ['is_active' => $previousActive],
                    newValues: ['is_active' => false],
                    request: $request
                );
            }
        });

        return $domain->fresh();
    }

    /**
     * Delete a domain database record and revoke edge certificate configuration.
     */
    public function deleteDomain(
        TenantCustomDomain $domain,
        ?User $actor = null,
        ?Request $request = null
    ): void {
        $tenant = $domain->tenant;
        $hostname = $domain->hostname;

        // 1. Deactivate first if active
        if ($domain->is_active) {
            $domain->is_active = false;
            $domain->save();
        }

        // 2. Revoke TLS provider configuration
        try {
            $this->tlsProvider->revokeCertificate($domain);
        } catch (Throwable $e) {
            report($e);
        }

        // 3. Delete database record
        $domain->delete();

        if ($actor && $tenant) {
            $this->logActivity(
                tenant: $tenant,
                actor: $actor,
                eventType: 'tenant.domain_deleted',
                description: "Deleted custom domain {$hostname}.",
                oldValues: ['hostname' => $hostname],
                newValues: null,
                request: $request
            );
        }
    }

    /**
     * Write custom-domain audit events to tenant_activity_logs.
     */
    public function logActivity(
        Tenant $tenant,
        User $actor,
        string $eventType,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): void {
        try {
            TenantActivityLog::create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $actor->id,
                'actor_type' => 'user',
                'event_type' => $eventType,
                'description' => $description,
                'subject_type' => Tenant::class,
                'subject_id' => $tenant->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
