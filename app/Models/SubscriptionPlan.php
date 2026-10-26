<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Existing fields
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_cycle',
        'is_active',
        'features',
        'max_whatsapp_messages',
        'max_ai_replies',
        'max_team_members',
        'max_whatsapp_accounts',
        'max_contacts',

        // Expanded plan configuration
        'code',
        'plan_type',
        'status',
        'visibility',
        'available_for_signup',
        'trial_days',
        'trial_eligibility',
        'setup_fee',
        'setup_fee_required',
        'onboarding_config',
        'sort_order',
        'published_at',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'currency' => 'string',
        'is_active' => 'boolean',
        'features' => 'array',

        'max_whatsapp_messages' => 'integer',
        'max_ai_replies' => 'integer',
        'max_team_members' => 'integer',
        'max_whatsapp_accounts' => 'integer',
        'max_contacts' => 'integer',

        'available_for_signup' => 'boolean',
        'trial_days' => 'integer',
        'trial_eligibility' => 'array',
        'setup_fee' => 'decimal:2',
        'setup_fee_required' => 'boolean',
        'onboarding_config' => 'array',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(
            TenantSubscription::class,
            'subscription_plan_id'
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(
            SubscriptionPlanVersion::class,
            'plan_id'
        );
    }

    public function prices(): HasMany
    {
        return $this->hasMany(
            SubscriptionPlanPrice::class,
            'plan_id'
        );
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(
            PlanFeature::class,
            'plan_id'
        );
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived'
            || $this->archived_at !== null;
    }

    public function isAvailableForSignup(): bool
    {
        return $this->is_active
            && $this->status === 'published'
            && $this->available_for_signup
            && ! $this->isArchived();
    }
}
