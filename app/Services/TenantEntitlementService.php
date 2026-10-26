<?php

namespace App\Services;

use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\User;

class TenantEntitlementService
{
    /**
     * Check if a tenant's subscription entitles them to white-label branding.
     */
    public function canUseWhiteLabel(Tenant $tenant, ?User $user = null): bool
    {
        return $this->hasFeatureEntitlement($tenant, 'white_label_enabled', $user);
    }

    /**
     * Check if a tenant's subscription entitles them to custom domains.
     */
    public function canUseCustomDomain(Tenant $tenant, ?User $user = null): bool
    {
        return $this->hasFeatureEntitlement($tenant, 'custom_domain_enabled', $user);
    }

    /**
     * Core feature check against active subscription plan features.
     */
    protected function hasFeatureEntitlement(Tenant $tenant, string $featureKey, ?User $user = null): bool
    {
        // Global Super Admin (not a regular tenant-scoped user) bypasses subscription plan limits
        $isSuperAdmin = $user && ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists());
        if ($isSuperAdmin) {
            $isTenantScopedUser = $user->memberships()->where('tenant_id', $tenant->id)->exists();
            if (! $isTenantScopedUser) {
                return true;
            }
        }

        $subscription = $tenant->currentSubscription;

        // If no active subscription exists, feature is denied
        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        $plan = $subscription->plan;

        // Check plan features
        $feature = PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', $featureKey)
            ->first();

        if ($feature) {
            return (bool) ($feature->value ?? false);
        }

        // If not explicitly defined in PlanFeature, check plan features array or default to false
        $planFeaturesArray = $plan->features ?? [];
        if (is_array($planFeaturesArray)) {
            foreach ($planFeaturesArray as $f) {
                if (stripos($f, str_replace('_', ' ', $featureKey)) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
