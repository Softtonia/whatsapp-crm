<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\Contact;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUsageLedger;
use App\Models\TenantUsageRecord;
use App\Models\TenantUser;
use App\Models\TenantWhatsAppAccount;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuotaEnforcementService
{
    public const METRIC_WHATSAPP_NUMBERS = 'max_whatsapp_numbers';
    public const METRIC_MONTHLY_CAMPAIGNS = 'max_monthly_campaigns';
    public const METRIC_WHATSAPP_MESSAGES = 'max_whatsapp_messages';
    public const METRIC_AI_REPLIES = 'max_ai_replies';
    public const METRIC_TEAM_MEMBERS = 'max_team_members';
    public const METRIC_CONTACTS = 'max_contacts';

    public function __construct(
        protected SubscriptionUsageService $usageService
    ) {}

    /**
     * Metric definitions metadata.
     */
    public function getMetricDefinitions(): array
    {
        return [
            self::METRIC_WHATSAPP_NUMBERS => [
                'metric_key' => self::METRIC_WHATSAPP_NUMBERS,
                'name' => 'Active WhatsApp Numbers',
                'description' => 'Number of active WhatsApp phone-number resources assigned to a tenant.',
                'unit' => 'number',
                'type' => 'current_resource', // current-resource limit
                'resets' => false,
                'reset_frequency' => 'never',
            ],
            self::METRIC_MONTHLY_CAMPAIGNS => [
                'metric_key' => self::METRIC_MONTHLY_CAMPAIGNS,
                'name' => 'Monthly Campaigns',
                'description' => 'Number of campaigns created during a tenant billing cycle.',
                'unit' => 'campaign',
                'type' => 'period_based', // period-based quota
                'resets' => true,
                'reset_frequency' => 'billing_cycle',
            ],
            self::METRIC_WHATSAPP_MESSAGES => [
                'metric_key' => self::METRIC_WHATSAPP_MESSAGES,
                'name' => 'WhatsApp Messages',
                'description' => 'Outbound and processed WhatsApp messages during billing cycle.',
                'unit' => 'message',
                'type' => 'period_based',
                'resets' => true,
                'reset_frequency' => 'billing_cycle',
            ],
            self::METRIC_AI_REPLIES => [
                'metric_key' => self::METRIC_AI_REPLIES,
                'name' => 'AI Reply Generations',
                'description' => 'AI-generated reply drafts and executions during billing cycle.',
                'unit' => 'generation',
                'type' => 'period_based',
                'resets' => true,
                'reset_frequency' => 'billing_cycle',
            ],
            self::METRIC_TEAM_MEMBERS => [
                'metric_key' => self::METRIC_TEAM_MEMBERS,
                'name' => 'Team Members',
                'description' => 'Active tenant team members and seats.',
                'unit' => 'user',
                'type' => 'current_resource',
                'resets' => false,
                'reset_frequency' => 'never',
            ],
            self::METRIC_CONTACTS => [
                'metric_key' => self::METRIC_CONTACTS,
                'name' => 'Total Contacts',
                'description' => 'Total addressable contacts stored for a tenant.',
                'unit' => 'contact',
                'type' => 'current_resource',
                'resets' => false,
                'reset_frequency' => 'never',
            ],
        ];
    }

    /**
     * Resolve effective plan limit for a tenant and metric.
     * Checks subscription plan columns, plan features, and subscription overrides.
     */
    public function getEffectiveLimit(int $tenantId, string $metric): ?int
    {
        $subscription = $this->usageService->getActiveSubscription($tenantId);

        if (! $subscription || ! $subscription->plan) {
            // Without an active plan, check if tenant has any subscription at all
            $hasAny = TenantSubscription::where('tenant_id', $tenantId)->exists();
            if ($hasAny) {
                // Expired or inactive plan has 0 allowance
                return 0;
            }
            // Truly unmanaged tenant defaults to unlimited (null)
            return null;
        }

        $plan = $subscription->plan;

        // Check if there is an active subscription override first
        $override = DB::table('subscription_overrides')
            ->where('tenant_id', $tenantId)
            ->where('subscription_id', $subscription->id)
            ->where('feature_key', $metric)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        if ($override && isset($override->override_value)) {
            $val = json_decode($override->override_value, true);
            if (is_numeric($val)) {
                return (int) $val;
            }
            if (is_array($val) && isset($val['limit']) && is_numeric($val['limit'])) {
                return (int) $val['limit'];
            }
        }

        // Check subscription plan direct columns
        switch ($metric) {
            case self::METRIC_WHATSAPP_NUMBERS:
                if ($plan->max_whatsapp_accounts !== null) {
                    return (int) $plan->max_whatsapp_accounts;
                }
                break;

            case self::METRIC_WHATSAPP_MESSAGES:
                if ($plan->max_whatsapp_messages !== null) {
                    return (int) $plan->max_whatsapp_messages;
                }
                break;

            case self::METRIC_AI_REPLIES:
                if ($plan->max_ai_replies !== null) {
                    return (int) $plan->max_ai_replies;
                }
                break;

            case self::METRIC_TEAM_MEMBERS:
                if ($plan->max_team_members !== null) {
                    return (int) $plan->max_team_members;
                }
                break;

            case self::METRIC_CONTACTS:
                if ($plan->max_contacts !== null) {
                    return (int) $plan->max_contacts;
                }
                break;
        }

        // Check PlanFeature records (e.g. max_monthly_campaigns or entitlement overrides)
        $feature = PlanFeature::where('plan_id', $plan->id)
            ->where('feature_key', $metric)
            ->first();

        if ($feature && $feature->value !== null) {
            $val = is_array($feature->value) ? ($feature->value['limit'] ?? $feature->value['value'] ?? null) : $feature->value;
            if ($val !== null && is_numeric($val)) {
                return (int) $val;
            }
        }

        // Return direct column value or null if unlimited
        return match ($metric) {
            self::METRIC_WHATSAPP_NUMBERS => $plan->max_whatsapp_accounts,
            self::METRIC_WHATSAPP_MESSAGES => $plan->max_whatsapp_messages,
            self::METRIC_AI_REPLIES => $plan->max_ai_replies,
            self::METRIC_TEAM_MEMBERS => $plan->max_team_members,
            self::METRIC_CONTACTS => $plan->max_contacts,
            self::METRIC_MONTHLY_CAMPAIGNS => null, // Default unlimited unless specified in plan features
            default => null,
        };
    }

    /**
     * Get current usage count for a tenant and metric.
     */
    public function getCurrentUsage(int $tenantId, string $metric): int
    {
        switch ($metric) {
            case self::METRIC_WHATSAPP_NUMBERS:
                return TenantWhatsAppAccount::where('tenant_id', $tenantId)->count();

            case self::METRIC_TEAM_MEMBERS:
                return TenantUser::where('tenant_id', $tenantId)->count();

            case self::METRIC_CONTACTS:
                return Contact::where('tenant_id', $tenantId)->count();

            case self::METRIC_WHATSAPP_MESSAGES:
                $record = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);
                return (int) $record->whatsapp_messages_count;

            case self::METRIC_AI_REPLIES:
                $record = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);
                return (int) $record->ai_replies_count;

            case self::METRIC_MONTHLY_CAMPAIGNS:
                $record = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);
                return (int) ($record->campaigns_count ?? 0);

            default:
                return 0;
        }
    }

    /**
     * Atomically check and reserve quota.
     * Prevents race conditions and double allocation.
     *
     * @throws QuotaExceededException|RuntimeException
     */
    public function reserveQuota(
        int $tenantId,
        string $metric,
        int $quantity = 1,
        ?string $idempotencyKey = null,
        ?string $sourceType = null,
        ?string $sourceId = null
    ): TenantUsageLedger {
        return DB::transaction(function () use (
            $tenantId,
            $metric,
            $quantity,
            $idempotencyKey,
            $sourceType,
            $sourceId
        ) {
            // 1. Idempotency check: if key already consumed, return existing ledger record without double counting
            if ($idempotencyKey) {
                $existingLedger = TenantUsageLedger::where('tenant_id', $tenantId)
                    ->where('metric_key', $metric)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingLedger) {
                    return $existingLedger;
                }
            }

            $effectiveLimit = $this->getEffectiveLimit($tenantId, $metric);
            $record = null;
            $currentUsage = 0;

            // 2. Lock aggregate row or count for update
            if (in_array($metric, [self::METRIC_WHATSAPP_MESSAGES, self::METRIC_AI_REPLIES, self::METRIC_MONTHLY_CAMPAIGNS], true)) {
                $currentRecord = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);
                $record = TenantUsageRecord::query()
                    ->where('id', $currentRecord->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentUsage = match ($metric) {
                    self::METRIC_WHATSAPP_MESSAGES => (int) $record->whatsapp_messages_count,
                    self::METRIC_AI_REPLIES => (int) $record->ai_replies_count,
                    self::METRIC_MONTHLY_CAMPAIGNS => (int) ($record->campaigns_count ?? 0),
                    default => 0,
                };
            } else {
                // Current-resource locking via tenant lock
                Tenant::query()->where('id', $tenantId)->lockForUpdate()->first();
                $currentUsage = $this->getCurrentUsage($tenantId, $metric);
            }

            // 3. Enforcement check
            if ($effectiveLimit !== null && ($currentUsage + $quantity) > $effectiveLimit) {
                $subscription = $this->usageService->getActiveSubscription($tenantId);
                $resetAt = $record ? $record->period_end?->toDateString() : null;

                throw new QuotaExceededException(
                    metricKey: $metric,
                    limit: $effectiveLimit,
                    currentUsage: $currentUsage,
                    resetAt: $resetAt,
                    message: "Quota limit reached for {$metric} (Limit: {$effectiveLimit}, Current: {$currentUsage})."
                );
            }

            // 4. Update aggregate counter atomically
            if ($record) {
                if ($metric === self::METRIC_WHATSAPP_MESSAGES) {
                    $record->increment('whatsapp_messages_count', $quantity);
                } elseif ($metric === self::METRIC_AI_REPLIES) {
                    $record->increment('ai_replies_count', $quantity);
                } elseif ($metric === self::METRIC_MONTHLY_CAMPAIGNS) {
                    $record->increment('campaigns_count', $quantity);
                }
            }

            // 5. Create immutable ledger record with idempotency constraint
            $period = $record ? [
                'start' => $record->period_start,
                'end' => $record->period_end,
            ] : [
                'start' => null,
                'end' => null,
            ];

            return TenantUsageLedger::create([
                'tenant_id' => $tenantId,
                'metric_key' => $metric,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * Release or refund a reservation if a business operation failed.
     */
    public function releaseReservation(TenantUsageLedger $ledger): void
    {
        DB::transaction(function () use ($ledger) {
            if (in_array($ledger->metric_key, [self::METRIC_WHATSAPP_MESSAGES, self::METRIC_AI_REPLIES, self::METRIC_MONTHLY_CAMPAIGNS], true)) {
                $record = TenantUsageRecord::where('tenant_id', $ledger->tenant_id)
                    ->whereDate('period_start', $ledger->period_start)
                    ->whereDate('period_end', $ledger->period_end)
                    ->lockForUpdate()
                    ->first();

                if ($record) {
                    if ($ledger->metric_key === self::METRIC_WHATSAPP_MESSAGES) {
                        $record->decrement('whatsapp_messages_count', min($record->whatsapp_messages_count, $ledger->quantity));
                    } elseif ($ledger->metric_key === self::METRIC_AI_REPLIES) {
                        $record->decrement('ai_replies_count', min($record->ai_replies_count, $ledger->quantity));
                    } elseif ($ledger->metric_key === self::METRIC_MONTHLY_CAMPAIGNS) {
                        $record->decrement('campaigns_count', min($record->campaigns_count ?? 0, $ledger->quantity));
                    }
                }
            }

            $ledger->delete();
        });
    }

    /**
     * Reconcile counters against authoritative records.
     * Safe to rerun, fully idempotent.
     */
    public function reconcileCounters(int $tenantId): array
    {
        return DB::transaction(function () use ($tenantId) {
            $record = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);

            // Reconcile periodic campaign count from authoritative ledgers in period
            $campaignLedgerSum = TenantUsageLedger::where('tenant_id', $tenantId)
                ->where('metric_key', self::METRIC_MONTHLY_CAMPAIGNS)
                ->whereDate('period_start', $record->period_start)
                ->whereDate('period_end', $record->period_end)
                ->sum('quantity');

            // Reconcile messages and ai replies from ledger if present, or keep existing
            $messagesLedgerSum = TenantUsageLedger::where('tenant_id', $tenantId)
                ->where('metric_key', self::METRIC_WHATSAPP_MESSAGES)
                ->whereDate('period_start', $record->period_start)
                ->whereDate('period_end', $record->period_end)
                ->sum('quantity');

            $aiLedgerSum = TenantUsageLedger::where('tenant_id', $tenantId)
                ->where('metric_key', self::METRIC_AI_REPLIES)
                ->whereDate('period_start', $record->period_start)
                ->whereDate('period_end', $record->period_end)
                ->sum('quantity');

            $updates = [];
            if ($campaignLedgerSum > 0 || $record->campaigns_count > 0) {
                $updates['campaigns_count'] = max((int) $campaignLedgerSum, (int) $record->campaigns_count);
            }
            if ($messagesLedgerSum > 0) {
                $updates['whatsapp_messages_count'] = max((int) $messagesLedgerSum, (int) $record->whatsapp_messages_count);
            }
            if ($aiLedgerSum > 0) {
                $updates['ai_replies_count'] = max((int) $aiLedgerSum, (int) $record->ai_replies_count);
            }

            if (! empty($updates)) {
                $record->update($updates);
            }

            return [
                'tenant_id' => $tenantId,
                'reconciled' => true,
                'period_start' => $record->period_start?->toDateString(),
                'period_end' => $record->period_end?->toDateString(),
                'record' => $record->fresh(),
            ];
        });
    }

    /**
     * Get structured effective quotas for a tenant.
     */
    public function getTenantQuotas(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $subscription = $this->usageService->getActiveSubscription($tenantId);
        $usageRecord = $this->usageService->getOrCreateCurrentUsageRecord($tenantId);
        $definitions = $this->getMetricDefinitions();

        $quotas = [];
        $isAnyOverLimit = false;

        foreach ($definitions as $metricKey => $def) {
            $effectiveLimit = $this->getEffectiveLimit($tenantId, $metricKey);
            $currentUsage = $this->getCurrentUsage($tenantId, $metricKey);
            $isUnlimited = ($effectiveLimit === null);
            $remaining = $isUnlimited ? null : max(0, $effectiveLimit - $currentUsage);
            $percentage = $isUnlimited ? 0.0 : ($effectiveLimit > 0 ? round(($currentUsage / $effectiveLimit) * 100, 2) : 100.0);
            $isOverLimit = (! $isUnlimited && $currentUsage > $effectiveLimit);

            if ($isOverLimit) {
                $isAnyOverLimit = true;
            }

            $resetAt = $def['resets'] ? $usageRecord->period_end?->toDateString() : null;

            $quotas[$metricKey] = [
                'metric' => $metricKey,
                'name' => $def['name'],
                'unit' => $def['unit'],
                'type' => $def['type'],
                'current_usage' => $currentUsage,
                'effective_limit' => $effectiveLimit,
                'remaining' => $remaining,
                'percentage' => $percentage,
                'is_unlimited' => $isUnlimited,
                'is_over_limit' => $isOverLimit,
                'reset_at' => $resetAt,
                'enforcement_policy' => 'hard_block',
                'enforcement_status' => $isOverLimit ? 'blocked' : 'normal',
            ];
        }

        return [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->company_name ?? $tenant->name,
                'status' => $tenant->status,
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => [
                    'id' => $subscription->plan?->id,
                    'name' => $subscription->plan?->name,
                    'billing_cycle' => $subscription->plan?->billing_cycle,
                ],
            ] : null,
            'period' => [
                'start' => $usageRecord->period_start?->toDateString(),
                'end' => $usageRecord->period_end?->toDateString(),
                'timezone' => config('app.timezone', 'UTC'),
            ],
            'is_over_limit' => $isAnyOverLimit,
            'quotas' => $quotas,
        ];
    }

    /**
     * Get platform-wide summary for GET /usage/summary.
     */
    public function getPlatformSummary(array $filters = []): array
    {
        $tenantsQuery = Tenant::query();
        if (! empty($filters['plan_id'])) {
            $tenantsQuery->whereHas('subscriptions', function ($q) use ($filters) {
                $q->where('subscription_plan_id', $filters['plan_id'])
                    ->where('status', 'active');
            });
        }

        $allTenants = $tenantsQuery->get(['id', 'company_name', 'name', 'status']);
        $definitions = $this->getMetricDefinitions();

        $metricAggregates = [];
        foreach ($definitions as $metricKey => $def) {
            $metricAggregates[$metricKey] = [
                'metric' => $metricKey,
                'name' => $def['name'],
                'unit' => $def['unit'],
                'type' => $def['type'],
                'total_usage' => 0,
                'tenants_over_limit' => 0,
            ];
        }

        $overLimitTenants = [];

        foreach ($allTenants as $tenant) {
            $tenantQuotas = $this->getTenantQuotas($tenant->id);
            $hasBreach = false;

            foreach ($tenantQuotas['quotas'] as $metricKey => $q) {
                $metricAggregates[$metricKey]['total_usage'] += $q['current_usage'];
                if ($q['is_over_limit']) {
                    $metricAggregates[$metricKey]['tenants_over_limit']++;
                    $hasBreach = true;
                }
            }

            if ($hasBreach) {
                $planName = isset($tenantQuotas['subscription']['plan']['name']) ? $tenantQuotas['subscription']['plan']['name'] : 'No Plan';
                $overLimitTenants[] = [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->company_name ?? $tenant->name,
                    'plan' => $planName,
                ];
            }
        }

        return [
            'total_tenants_included' => $allTenants->count(),
            'tenants_at_or_over_limit' => count($overLimitTenants),
            'over_limit_tenants' => $overLimitTenants,
            'metric_aggregates' => array_values($metricAggregates),
            'period' => [
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->endOfMonth()->toDateString(),
                'timezone' => config('app.timezone', 'UTC'),
            ],
            'metric_definitions' => array_values($definitions),
        ];
    }

    /**
     * Get paginated tenant list with usage and quota comparisons for GET /usage/tenants.
     */
    public function getTenantsUsageList(array $filters = [], int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $query = Tenant::query();

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('company_name', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('id', $filters['tenant_id']);
        }

        if (! empty($filters['plan_id'])) {
            $query->whereHas('subscriptions', function ($q) use ($filters) {
                $q->where('subscription_plan_id', $filters['plan_id'])
                    ->where('status', 'active');
            });
        }

        $tenants = $query->orderBy('id')->get();

        $metricFilter = $filters['metric'] ?? null;
        $overLimitFilter = isset($filters['over_limit']) ? filter_var($filters['over_limit'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        $items = [];
        foreach ($tenants as $tenant) {
            $quotaData = $this->getTenantQuotas($tenant->id);

            if ($overLimitFilter !== null && $quotaData['is_over_limit'] !== $overLimitFilter) {
                continue;
            }

            // Filter specific metric if requested
            $displayedQuotas = $quotaData['quotas'];
            if ($metricFilter && isset($displayedQuotas[$metricFilter])) {
                $displayedQuotas = [$metricFilter => $displayedQuotas[$metricFilter]];
            }

            $items[] = [
                'tenant_id' => $tenant->id,
                'name' => $tenant->company_name ?? $tenant->name,
                'status' => $tenant->status,
                'subscription' => $quotaData['subscription'],
                'period' => $quotaData['period'],
                'is_over_limit' => $quotaData['is_over_limit'],
                'enforcement_status' => $quotaData['is_over_limit'] ? 'blocked' : 'normal',
                'quotas' => $displayedQuotas,
            ];
        }

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Get historical usage for a tenant for GET /tenants/{id}/usage.
     */
    public function getTenantUsageHistory(int $tenantId, array $filters = [], int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $tenant = Tenant::findOrFail($tenantId);
        $query = TenantUsageRecord::where('tenant_id', $tenantId)->orderByDesc('period_start');

        if (! empty($filters['period_start'])) {
            $query->whereDate('period_start', '>=', $filters['period_start']);
        }
        if (! empty($filters['period_end'])) {
            $query->whereDate('period_end', '<=', $filters['period_end']);
        }

        $records = $query->get();
        $definitions = $this->getMetricDefinitions();
        $metricFilter = $filters['metric'] ?? null;

        $historyRows = [];
        foreach ($records as $record) {
            $metrics = [
                self::METRIC_WHATSAPP_MESSAGES => (int) $record->whatsapp_messages_count,
                self::METRIC_AI_REPLIES => (int) $record->ai_replies_count,
                self::METRIC_MONTHLY_CAMPAIGNS => (int) ($record->campaigns_count ?? 0),
            ];

            foreach ($metrics as $metricKey => $usageAmount) {
                if ($metricFilter && $metricFilter !== $metricKey) {
                    continue;
                }

                $def = $definitions[$metricKey] ?? null;
                $effectiveLimit = $this->getEffectiveLimit($tenantId, $metricKey);
                $isUnlimited = ($effectiveLimit === null);
                $isOver = (! $isUnlimited && $usageAmount > $effectiveLimit);
                $percentage = $isUnlimited ? 0.0 : ($effectiveLimit > 0 ? round(($usageAmount / $effectiveLimit) * 100, 2) : 100.0);

                $historyRows[] = [
                    'tenant_id' => $tenantId,
                    'metric_key' => $metricKey,
                    'metric_name' => $def['name'] ?? $metricKey,
                    'unit' => $def['unit'] ?? 'count',
                    'usage_amount' => $usageAmount,
                    'effective_limit' => $effectiveLimit,
                    'is_unlimited' => $isUnlimited,
                    'percentage' => $percentage,
                    'is_over_limit' => $isOver,
                    'period_start' => $record->period_start?->toDateString(),
                    'period_end' => $record->period_end?->toDateString(),
                    'aggregation_method' => 'sum',
                    'created_at' => $record->created_at?->toDateTimeString(),
                ];
            }
        }

        $total = count($historyRows);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($historyRows, $offset, $perPage);

        return new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
