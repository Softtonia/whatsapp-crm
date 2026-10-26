<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'subscription_plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_starts_at',
        'trial_ends_at',
        'billing_interval',
        'currency',
        'base_price',
        'setup_fee',
        'discount_amount',
        'billing_period_starts_at',
        'billing_period_ends_at',
        'next_billing_at',
        'grace_period_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        'ended_at',
        'assigned_by',
        'plan_version_id',
        'plan_price_id',
        'metadata',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'billing_period_starts_at' => 'datetime',
            'billing_period_ends_at' => 'datetime',
            'next_billing_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'base_price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            SubscriptionPlan::class,
            'subscription_plan_id'
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(
            TenantSubscriptionEvent::class,
            'subscription_id'
        )->latest('created_at');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (
                $this->ends_at === null
                || $this->ends_at->isFuture()
            );
    }
    public function invoices(): HasMany
    {
        return $this->hasMany(
            SubscriptionInvoice::class,
            'subscription_id'
        );
    }
}
