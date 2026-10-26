<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantHealthCheck extends Model
{
    protected $table = 'tenant_health_checks';

    protected $fillable = [
        'tenant_id',
        'check_key',
        'category',
        'status',
        'message',
        'details',
        'checked_at',
        'next_check_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }
}
