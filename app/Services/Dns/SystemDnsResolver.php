<?php

namespace App\Services\Dns;

use App\Services\Contracts\DnsResolverInterface;
use RuntimeException;

class SystemDnsResolver implements DnsResolverInterface
{
    /**
     * Resolve TXT records using PHP native dns_get_record.
     * Handles string records and multi-part chunked entries.
     *
     * @return array<int, string>
     */
    public function resolveTxt(string $hostname): array
    {
        if (! function_exists('dns_get_record')) {
            throw new RuntimeException('The PHP DNS lookup function is unavailable.');
        }

        $dnsResult = @dns_get_record($hostname, DNS_TXT);

        if ($dnsResult === false) {
            throw new RuntimeException("DNS TXT query failed for host {$hostname}.");
        }

        $records = [];

        foreach ($dnsResult as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $records[] = $record['txt'];
                continue;
            }

            if (isset($record['entries']) && is_array($record['entries'])) {
                // Multi-chunk RFC 1035 character-strings inside a single TXT RR
                $records[] = implode('', $record['entries']);
            }
        }

        return $records;
    }
}
