<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'version_id',
        'billing_interval',
        'interval_count',
        'currency',
        'amount',
        'setup_fee',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'interval_count' => 'integer',
        'amount' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanVersion::class, 'version_id');
    }
}
