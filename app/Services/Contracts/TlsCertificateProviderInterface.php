<?php

namespace App\Services\Contracts;

use App\Models\TenantCustomDomain;

interface TlsCertificateProviderInterface
{
    /**
     * Request or initiate TLS certificate provisioning for the verified custom domain.
     *
     * @param TenantCustomDomain $domain
     * @return array{success: bool, status: string, message: string, metadata?: array}
     */
    public function provisionCertificate(TenantCustomDomain $domain): array;

    /**
     * Check certificate status and validity for the domain.
     *
     * @param TenantCustomDomain $domain
     * @return array{ready: bool, status: string, message: string, metadata?: array}
     */
    public function checkCertificateStatus(TenantCustomDomain $domain): array;

    /**
     * Revoke or remove certificate/routing for a deactivated or deleted domain.
     *
     * @param TenantCustomDomain $domain
     * @return array{success: bool, message: string}
     */
    public function revokeCertificate(TenantCustomDomain $domain): array;
}
