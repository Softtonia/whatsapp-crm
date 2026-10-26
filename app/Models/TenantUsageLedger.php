<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'metric_key',
        'quantity',
        'source_type',
        'source_id',
        'idempotency_key',
        'period_start',
        'period_end',
        'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'occurred_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }
}
