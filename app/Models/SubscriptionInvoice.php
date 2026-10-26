<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionInvoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'invoice_number',
        'status',
        'currency',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'amount_paid',
        'amount_due',
        'billing_period_start',
        'billing_period_end',
        'issued_at',
        'due_at',
        'paid_at',
        'voided_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'billing_period_start' => 'datetime',
            'billing_period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            TenantSubscription::class,
            'subscription_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            SubscriptionInvoiceItem::class,
            'invoice_id'
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(
            SubscriptionPayment::class,
            'invoice_id'
        )->latest('created_at');
    }
}