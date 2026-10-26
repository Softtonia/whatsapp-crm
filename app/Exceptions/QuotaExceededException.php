<?php

namespace App\Exceptions;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public string $metricKey,
        public ?int $limit,
        public int $currentUsage,
        public ?string $resetAt = null,
        string $message = ''
    ) {
        if (empty($message)) {
            $message = "Quota limit reached for {$metricKey} (Limit: " . ($limit ?? 'unlimited') . ", Used: {$currentUsage}).";
        }
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return [
            'metric' => $this->metricKey,
            'limit' => $this->limit,
            'current_usage' => $this->currentUsage,
            'reset_at' => $this->resetAt,
        ];
    }
}
