<?php

namespace App\Services\Dns;

use App\Services\Contracts\DnsResolverInterface;
use RuntimeException;

class FakeDnsResolver implements DnsResolverInterface
{
    /** @var array<string, array<int, string>> */
    protected array $records = [];

    protected bool $shouldThrow = false;
    protected string $exceptionMessage = 'Simulated DNS timeout';

    public function setTxtRecords(string $hostname, array $records): self
    {
        $this->records[strtolower(trim($hostname))] = $records;
        return $this;
    }

    public function shouldThrow(bool $throw = true, string $message = 'Simulated DNS timeout'): self
    {
        $this->shouldThrow = $throw;
        $this->exceptionMessage = $message;
        return $this;
    }

    public function resolveTxt(string $hostname): array
    {
        if ($this->shouldThrow) {
            throw new RuntimeException($this->exceptionMessage);
        }

        $normalized = strtolower(trim($hostname));

        return $this->records[$normalized] ?? [];
    }
}
