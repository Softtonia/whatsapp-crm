<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:process-lifecycle';

    protected $description =
    'Process subscription cancellations, scheduled changes, trial expiry and grace-period expiry';

    public function handle(): int
    {
        $cancellations = $this->processPeriodEndCancellations();
        $changes = $this->processScheduledPlanChanges();
        $trialExpiries = $this->processExpiredTrials();
        $graceExpiries = $this->processExpiredGracePeriods();

        $this->info(
            "Period-end cancellations processed: {$cancellations}"
        );

        $this->info(
            "Scheduled plan changes processed: {$changes}"
        );

        $this->info(
            "Expired trials processed: {$trialExpiries}"
        );

        $this->info(
            "Expired grace periods processed: {$graceExpiries}"
        );

        return self::SUCCESS;
    }

    /**
     * Cancel subscriptions whose billing period has ended.
     */
    private function processPeriodEndCancellations(): int
    {
        $now = now();
        $processed = 0;

        $ids = TenantSubscription::query()
            ->where('cancel_at_period_end', true)
            ->whereIn('status', ['active', 'trial', 'past_due'])
            ->where(function ($query) use ($now) {
                $query->where(function ($query) use ($now) {
                    $query->whereNotNull('billing_period_ends_at')
                        ->where('billing_period_ends_at', '<=', $now);
                })->orWhere(function ($query) use ($now) {
                    $query->whereNull('billing_period_ends_at')
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<=', $now);
                });
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            $wasProcessed = DB::transaction(function () use ($id) {
                $subscription = TenantSubscription::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $subscription
                    || ! $subscription->cancel_at_period_end
                    || ! in_array(
                        $subscription->status,
                        ['active', 'trial', 'past_due'],
                        true
                    )
                ) {
                    return false;
                }

                $periodEnd = $subscription->billing_period_ends_at
                    ?? $subscription->ends_at;

                if (! $periodEnd || $periodEnd->isFuture()) {
                    return false;
                }

                $oldValues = $subscription->only([
                    'status',
                    'cancel_at_period_end',
                    'cancelled_at',
                    'ended_at',
                ]);

                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'ended_at' => $periodEnd,
                    'cancel_at_period_end' => false,
                    'next_billing_at' => null,
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'event_type' =>
                    'subscription_cancelled_at_period_end',
                    'old_values' => $oldValues,
                    'new_values' => $subscription->fresh()->only([
                        'status',
                        'cancel_at_period_end',
                        'cancelled_at',
                        'ended_at',
                        'next_billing_at',
                    ]),
                    'reason' => 'Billing period ended.',
                    'source' => 'system',
                ]);

                return true;
            });

            if ($wasProcessed) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Apply scheduled plan changes whose effective date has arrived.
     */
    private function processScheduledPlanChanges(): int
    {
        $now = now();
        $processed = 0;

        $ids = DB::table('subscription_change_requests')
            ->where('status', 'pending')
            ->where('effective_timing', 'scheduled')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', $now)
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            $wasProcessed = DB::transaction(function () use ($id) {
                $change = DB::table('subscription_change_requests')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $change
                    || $change->status !== 'pending'
                    || $change->effective_timing !== 'scheduled'
                    || ! $change->effective_at
                    || now()->lt($change->effective_at)
                ) {
                    return false;
                }

                $subscription = TenantSubscription::query()
                    ->whereKey($change->subscription_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $subscription
                    || ! in_array(
                        $subscription->status,
                        ['active', 'trial', 'past_due'],
                        true
                    )
                ) {
                    DB::table('subscription_change_requests')
                        ->where('id', $change->id)
                        ->update([
                            'status' => 'cancelled',
                            'reviewed_at' => now(),
                            'updated_at' => now(),
                        ]);

                    return true;
                }

                $plan = SubscriptionPlan::query()
                    ->where('is_active', true)
                    ->where('status', 'published')
                    ->whereNull('archived_at')
                    ->find($change->requested_plan_id);

                if (! $plan) {
                    DB::table('subscription_change_requests')
                        ->where('id', $change->id)
                        ->update([
                            'status' => 'failed',
                            'reviewed_at' => now(),
                            'updated_at' => now(),
                        ]);

                    return true;
                }


                $oldValues = [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'status' => $subscription->status,
                    'base_price' => $subscription->base_price,
                    'currency' => $subscription->currency,
                    'billing_interval' => $subscription->billing_interval,
                    'setup_fee' => $subscription->setup_fee,
                ];

                $subscription->update([
                    'subscription_plan_id' => $plan->id,
                    'base_price' => $plan->price,
                    'currency' => $plan->currency,
                    'billing_interval' => $plan->billing_cycle,
                    'setup_fee' => $plan->setup_fee ?? 0,
                ]);

                $newValues = [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'status' => $subscription->status,
                    'base_price' => $subscription->base_price,
                    'currency' => $subscription->currency,
                    'billing_interval' => $subscription->billing_interval,
                    'setup_fee' => $subscription->setup_fee,
                    'change_request_id' => $change->id,
                ];


                DB::table('subscription_change_requests')
                    ->where('id', $change->id)
                    ->update([
                        'status' => 'completed',
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'event_type' => 'scheduled_plan_change_applied',
                    'old_values' => $oldValues,
                    'new_values' =>  $newValues,
                    'reason' => $change->reason
                        ?? 'Scheduled plan change applied.',
                    'source' => 'system',
                ]);

                return true;
            });

            if ($wasProcessed) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Expire trials that have passed their trial end date.
     */
    private function processExpiredTrials(): int
    {
        $now = now();
        $processed = 0;

        $ids = TenantSubscription::query()
            ->where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            $wasProcessed = DB::transaction(function () use ($id) {
                $subscription = TenantSubscription::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $subscription
                    || $subscription->status !== 'trial'
                    || ! $subscription->trial_ends_at
                    || $subscription->trial_ends_at->isFuture()
                ) {
                    return false;
                }

                $oldValues = $subscription->only([
                    'status',
                    'trial_ends_at',
                    'ends_at',
                    'ended_at',
                    'next_billing_at',
                ]);

                $subscription->update([
                    'status' => 'expired',
                    'ended_at' => $subscription->trial_ends_at,
                    'next_billing_at' => null,
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'event_type' => 'subscription_trial_expired',
                    'old_values' => $oldValues,
                    'new_values' => $subscription->fresh()->only([
                        'status',
                        'trial_ends_at',
                        'ends_at',
                        'ended_at',
                        'next_billing_at',
                    ]),
                    'reason' => 'Trial period expired.',
                    'source' => 'system',
                ]);

                return true;
            });

            if ($wasProcessed) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Suspend subscriptions whose payment grace period has expired.
     */
    private function processExpiredGracePeriods(): int
    {
        $now = now();
        $processed = 0;

        $ids = TenantSubscription::query()
            ->where('status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', $now)
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($ids as $id) {
            $wasProcessed = DB::transaction(function () use ($id) {
                $subscription = TenantSubscription::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $subscription
                    || $subscription->status !== 'past_due'
                    || ! $subscription->grace_period_ends_at
                    || $subscription->grace_period_ends_at->isFuture()
                ) {
                    return false;
                }

                $oldValues = $subscription->only([
                    'status',
                    'grace_period_ends_at',
                ]);

                $subscription->update([
                    'status' => 'suspended',
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'event_type' =>
                    'subscription_grace_period_expired',
                    'old_values' => $oldValues,
                    'new_values' => $subscription->fresh()->only([
                        'status',
                        'grace_period_ends_at',
                    ]),
                    'reason' =>
                    'Grace period expired while payment remained overdue.',
                    'source' => 'system',
                ]);

                return true;
            });

            if ($wasProcessed) {
                $processed++;
            }
        }

        return $processed;
    }
}
