<?php

namespace App\Services\Tls;

use App\Models\TenantCustomDomain;
use App\Services\Contracts\TlsCertificateProviderInterface;

class FakeTlsProvider implements TlsCertificateProviderInterface
{
    protected string $defaultStatus = 'ready';
    protected bool $shouldFail = false;

    public function setStatus(string $status): void
    {
        $this->defaultStatus = $status;
    }

    public function shouldFail(bool $fail = true): void
    {
        $this->shouldFail = $fail;
    }

    public function provisionCertificate(TenantCustomDomain $domain): array
    {
        if ($this->shouldFail) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Simulated TLS provisioning failure: ACME challenge unreachable.',
                'metadata' => [
                    'provider' => 'fake',
                    'error' => 'acme_unreachable',
                ],
            ];
        }

        if (! $domain->isVerified()) {
            return [
                'success' => false,
                'status' => 'pending',
                'message' => 'Cannot request TLS certificate until DNS ownership is verified.',
            ];
        }

        $ready = ($this->defaultStatus === 'ready');

        return [
            'success' => $ready,
            'status' => $this->defaultStatus,
            'message' => $ready ? 'TLS certificate provisioned successfully.' : 'TLS certificate provisioning is pending.',
            'metadata' => [
                'provider' => 'fake',
                'issuer' => "Let's Encrypt Authority X3",
                'issued_at' => now()->toIso8601String(),
                'expires_at' => now()->addDays(90)->toIso8601String(),
                'serial_number' => 'FAKE-CERT-' . strtoupper(substr(md5($domain->hostname), 0, 16)),
            ],
        ];
    }

    public function checkCertificateStatus(TenantCustomDomain $domain): array
    {
        $isReady = ($domain->tls_status === 'ready' || $this->defaultStatus === 'ready');

        return [
            'ready' => $isReady,
            'status' => $isReady ? 'ready' : 'pending',
            'message' => $isReady ? 'Certificate valid and active.' : 'Certificate is not ready.',
            'metadata' => $domain->tls_metadata ?? [],
        ];
    }

    public function revokeCertificate(TenantCustomDomain $domain): array
    {
        return [
            'success' => true,
            'message' => 'Simulated certificate revocation completed.',
        ];
    }
}
