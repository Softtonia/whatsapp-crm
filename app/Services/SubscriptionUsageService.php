<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantWhatsAppAccount;
use App\Models\Contact;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\TenantUsageRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionUsageService
{
    /**
     * Get or create the usage record for the current billing cycle.
     */
    public function getOrCreateCurrentUsageRecord(int $tenantId): TenantUsageRecord
    {
        $activeSubscription = $this->getActiveSubscription($tenantId);

        if ($activeSubscription && $activeSubscription->starts_at) {
            $period = $this->calculateBillingPeriod(
                $activeSubscription->starts_at,
                $activeSubscription->plan?->billing_cycle ?? 'monthly'
            );
        } else {
            // Default calendar month if no subscription with specific start date
            $period = [
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->endOfMonth()->toDateString(),
            ];
        }

        $existing = TenantUsageRecord::where('tenant_id', $tenantId)
            ->whereDate('period_start', $period['start'])
            ->whereDate('period_end', $period['end'])
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return TenantUsageRecord::create([
                'tenant_id' => $tenantId,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'whatsapp_messages_count' => 0,
                'ai_replies_count' => 0,
            ]);
        } catch (\Throwable $e) {
            // Concurrent insert race condition caught; return the inserted row
            return TenantUsageRecord::where('tenant_id', $tenantId)
                ->whereDate('period_start', $period['start'])
                ->whereDate('period_end', $period['end'])
                ->firstOrFail();
        }
    }

    /**
     * Calculate current cycle start and end dates based on subscription starts_at and billing cycle.
     */
    public function calculateBillingPeriod(Carbon $startsAt, string $billingCycle): array
    {
        $now = now();

        if ($billingCycle === 'yearly') {
            $diffYears = $startsAt->diffInYears($now);
            $currentStart = $startsAt->copy()->addYears($diffYears)->startOfDay();
            if ($currentStart->isAfter($now)) {
                $currentStart = $startsAt->copy()->addYears($diffYears - 1)->startOfDay();
            }
            $currentEnd = $currentStart->copy()->addYear()->subDay()->endOfDay();
        } else {
            // Default monthly
            $diffMonths = $startsAt->diffInMonths($now);
            $currentStart = $startsAt->copy()->addMonths($diffMonths)->startOfDay();
            if ($currentStart->isAfter($now)) {
                $currentStart = $startsAt->copy()->addMonths($diffMonths - 1)->startOfDay();
            }
            $currentEnd = $currentStart->copy()->addMonth()->subDay()->endOfDay();
        }

        return [
            'start' => $currentStart->toDateString(),
            'end' => $currentEnd->toDateString(),
        ];
    }

    /**
     * Get the active subscription for a tenant.
     */
    public function getActiveSubscription(int $tenantId): ?TenantSubscription
    {
        return TenantSubscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    /**
     * Assert that a tenant can perform an action without exceeding its plan limits.
     * Throws RuntimeException with a clear user-facing message if limit is reached or subscription is expired/missing.
     *
     * @param string $feature 'whatsapp_messages' | 'ai_replies' | 'team_members' | 'whatsapp_accounts' | 'contacts'
     * @param int $quantity Amount to add (default 1)
     */
    public function assertCanUse(int $tenantId, string $feature, int $quantity = 1): void
    {
        $subscription = $this->getActiveSubscription($tenantId);

        // If a tenant has an expired subscription record, reject with expired message
        $hasAnySubscription = TenantSubscription::where('tenant_id', $tenantId)->exists();
        if ($hasAnySubscription && ! $subscription) {
            throw new RuntimeException('Your subscription plan has expired or is inactive. Please renew your plan.');
        }

        // If no subscription plan exists yet, allow unless plan is explicitly enforced,
        // or if a plan exists on subscription, enforce the plan's limits.
        if (! $subscription || ! $subscription->plan) {
            return;
        }

        $plan = $subscription->plan;

        switch ($feature) {
            case 'whatsapp_messages':
                $this->assertPeriodUsageLimit(
                    $tenantId,
                    'whatsapp_messages',
                    $plan->max_whatsapp_messages,
                    $quantity,
                    'WhatsApp messages'
                );
                break;

            case 'ai_replies':
                $this->assertPeriodUsageLimit(
                    $tenantId,
                    'ai_replies',
                    $plan->max_ai_replies,
                    $quantity,
                    'AI reply generations'
                );
                break;

            case 'team_members':
                if ($plan->max_team_members !== null) {
                    $currentCount = TenantUser::where('tenant_id', $tenantId)->count();
                    if (($currentCount + $quantity) > $plan->max_team_members) {
                        throw new RuntimeException(
                            "Team members limit reached for this subscription plan (Maximum: {$plan->max_team_members})."
                        );
                    }
                }
                break;

            case 'whatsapp_accounts':
                if ($plan->max_whatsapp_accounts !== null) {
                    $currentCount = TenantWhatsAppAccount::where('tenant_id', $tenantId)->count();
                    if (($currentCount + $quantity) > $plan->max_whatsapp_accounts) {
                        throw new RuntimeException(
                            "WhatsApp accounts limit reached for this subscription plan (Maximum: {$plan->max_whatsapp_accounts})."
                        );
                    }
                }
                break;

            case 'contacts':
                if ($plan->max_contacts !== null) {
                    $currentCount = Contact::where('tenant_id', $tenantId)->count();
                    if (($currentCount + $quantity) > $plan->max_contacts) {
                        throw new RuntimeException(
                            "Contacts limit reached for this subscription plan (Maximum: {$plan->max_contacts})."
                        );
                    }
                }
                break;
            case 'campaigns':
            case 'max_monthly_campaigns':
                $campaignLimit = app(\App\Services\QuotaEnforcementService::class)->getEffectiveLimit($tenantId, 'max_monthly_campaigns');
                $this->assertPeriodUsageLimit(
                    $tenantId,
                    'campaigns',
                    $campaignLimit,
                    $quantity,
                    'Monthly campaigns'
                );
                break;
        }
    }

    /**
     * Check periodic usage (e.g., messages, AI replies, campaigns) inside atomic check.
     */
    protected function assertPeriodUsageLimit(
        int $tenantId,
        string $type,
        ?int $limit,
        int $quantity,
        string $label
    ): void {
        if ($limit === null) {
            // Null means explicitly unlimited
            return;
        }

        $record = $this->getOrCreateCurrentUsageRecord($tenantId);
        $column = match ($type) {
            'whatsapp_messages', 'max_whatsapp_messages' => 'whatsapp_messages_count',
            'ai_replies', 'max_ai_replies' => 'ai_replies_count',
            'campaigns', 'max_monthly_campaigns' => 'campaigns_count',
            default => 'whatsapp_messages_count',
        };
        $currentUsage = (int) ($record->{$column} ?? 0);

        if (($currentUsage + $quantity) > $limit) {
            throw new RuntimeException(
                "Subscription limit reached for {$label} (Limit: {$limit}, Used: {$currentUsage}). Please upgrade your plan."
            );
        }
    }

    /**
     * Record usage atomically after successful execution.
     */
    public function recordUsage(int $tenantId, string $type, int $quantity = 1, ?string $idempotencyKey = null, ?string $sourceType = null, ?string $sourceId = null): void
    {
        $metricKey = match ($type) {
            'whatsapp_messages' => 'max_whatsapp_messages',
            'ai_replies' => 'max_ai_replies',
            'campaigns' => 'max_monthly_campaigns',
            default => $type,
        };

        app(\App\Services\QuotaEnforcementService::class)->reserveQuota(
            tenantId: $tenantId,
            metric: $metricKey,
            quantity: $quantity,
            idempotencyKey: $idempotencyKey,
            sourceType: $sourceType,
            sourceId: $sourceId
        );
    }

    /**
     * Fetch complete subscription and usage overview for a tenant.
     */
    public function getSubscriptionSummary(int $tenantId): array
    {
        $subscription = $this->getActiveSubscription($tenantId);
        $usage = $this->getOrCreateCurrentUsageRecord($tenantId);

        $teamCount = TenantUser::where('tenant_id', $tenantId)->count();
        $accountCount = TenantWhatsAppAccount::where('tenant_id', $tenantId)->count();
        $contactCount = Contact::where('tenant_id', $tenantId)->count();

        $plan = $subscription?->plan;

        return [
            'has_active_subscription' => $subscription !== null,
            'subscription' => $subscription,
            'plan' => $plan,
            'billing_period' => [
                'start' => $usage->period_start?->toDateString(),
                'end' => $usage->period_end?->toDateString(),
            ],
            'usage' => [
                'whatsapp_messages' => [
                    'used' => $usage->whatsapp_messages_count,
                    'limit' => $plan?->max_whatsapp_messages,
                    'is_unlimited' => $plan ? $plan->max_whatsapp_messages === null : true,
                ],
                'ai_replies' => [
                    'used' => $usage->ai_replies_count,
                    'limit' => $plan?->max_ai_replies,
                    'is_unlimited' => $plan ? $plan->max_ai_replies === null : true,
                ],
                'team_members' => [
                    'used' => $teamCount,
                    'limit' => $plan?->max_team_members,
                    'is_unlimited' => $plan ? $plan->max_team_members === null : true,
                ],
                'whatsapp_accounts' => [
                    'used' => $accountCount,
                    'limit' => $plan?->max_whatsapp_accounts,
                    'is_unlimited' => $plan ? $plan->max_whatsapp_accounts === null : true,
                ],
                'contacts' => [
                    'used' => $contactCount,
                    'limit' => $plan?->max_contacts,
                    'is_unlimited' => $plan ? $plan->max_contacts === null : true,
                ],
            ],
        ];
    }
}
