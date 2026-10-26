<?php

namespace App\Services\Contracts;

interface DnsResolverInterface
{
    /**
     * Resolve TXT records for a given hostname.
     *
     * @param string $hostname
     * @return array<int, string>
     * @throws \RuntimeException
     */
    public function resolveTxt(string $hostname): array;
}
