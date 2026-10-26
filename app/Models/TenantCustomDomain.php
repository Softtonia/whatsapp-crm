<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCustomDomain extends Model
{
    protected $table = 'tenant_custom_domains';

    protected $fillable = [
        'tenant_id',
        'hostname',
        'verification_status',
        'verification_record_type',
        'verification_hostname',
        'verification_token',
        'expected_record_value',
        'verified_at',
        'tls_status',
        'tls_metadata',
        'is_active',
        'last_verification_attempt_at',
        'last_verification_error',
    ];

    protected function casts(): array
    {
        return [
            'tls_metadata' => 'array',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
            'last_verification_attempt_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}