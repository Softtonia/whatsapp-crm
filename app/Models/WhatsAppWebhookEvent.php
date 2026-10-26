<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $fillable = [
        'event_id',
        'idempotency_key',
        'event_type',
        'tenant_id',
        'phone_number_id',
        'waba_id',
        'payload',
        'processing_status',
        'attempt_count',
        'last_error',
        'last_attempt_at',
        'processed_at',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
