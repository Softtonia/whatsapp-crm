<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $table = 'tenants';


    protected $fillable = [
        // Tenant business profile fields
        'company_name',
        'name',
        'slug',
        'logo',
        'owner_user_id',
        'contact_number',
        'email',
        'address',
        'country',
        'currency',
        'timezone',
        'suspend_reason',

        // Tenant offboarding and purge fields
        'archived_at',
        'legal_hold_at',
        'legal_hold_reason',
        'retention_until',
        'purge_eligible_at',
        'purge_status',
        'purge_requested_by',
        'purge_approved_by',
        'purge_requested_at',
        'purge_approved_at',
        'purge_started_at',
        'purge_completed_at',
        'purge_reason',
        'purge_preview',

        // Existing workflow fields
        'tenant_id',
        'requested_by',
        'approved_by',
        'status',
        'reason',
        'options',
        'metadata',
        'scheduled_for',
        'started_at',
        'completed_at',
        'cancelled_at',
        'onboarding_status',
        'onboarding_completed_at',
    ];


    protected function casts(): array
    {
        return [
            'options' => 'array',
            'metadata' => 'array',
            'purge_preview' => 'array',

            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',

            'archived_at' => 'datetime',
            'retention_until' => 'datetime',
            'purge_eligible_at' => 'datetime',
            'purge_requested_at' => 'datetime',
            'purge_approved_at' => 'datetime',
            'purge_started_at' => 'datetime',
            'purge_completed_at' => 'datetime',

            'purge_requested_by' => 'integer',
            'purge_approved_by' => 'integer',

            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * Backward-compatibility accessor for name => company_name.
     */
    public function getNameAttribute(): ?string
    {
        return $this->attributes['company_name']
            ?? $this->attributes['name']
            ?? null;
    }

    /**
     * Backward-compatibility mutator for name => company_name.
     */
    public function setNameAttribute($value): void
    {
        $this->attributes['company_name'] = $value;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'tenant_users',
            'tenant_id',
            'user_id'
        )->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(TenantWhatsAppAccount::class, 'tenant_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'tenant_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'tenant_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'tenant_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'tenant_id');
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(TenantKnowledge::class, 'tenant_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class, 'tenant_id');
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class, 'tenant_id')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latestOfMany();
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(TenantUsageRecord::class, 'tenant_id');
    }

    public function subscriptionEvents(): HasMany
    {
        return $this->hasMany(TenantSubscriptionEvent::class, 'tenant_id');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(TenantHealthCheck::class, 'tenant_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TenantStatusHistory::class, 'tenant_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(TenantActivityLog::class, 'tenant_id');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(TenantInternalNote::class, 'tenant_id');
    }

    public function bulkOperationItems(): HasMany
    {
        return $this->hasMany(TenantBulkOperationItem::class, 'tenant_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(TenantExport::class, 'tenant_id');
    }

    public function offboardingJobs(): HasMany
    {
        return $this->hasMany(TenantOffboardingJob::class, 'tenant_id');
    }
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'tenant_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'tenant_id');
    }

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class, 'tenant_id');
    }

    public function customDomains(): HasMany
    {
        return $this->hasMany(TenantCustomDomain::class, 'tenant_id');
    }
}
