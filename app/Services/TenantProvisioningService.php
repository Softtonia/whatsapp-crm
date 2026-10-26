<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantProvisioningJob;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TenantProvisioningService
{
    public function run(TenantProvisioningJob $job): TenantProvisioningJob
    {
        $job->refresh();

        if ($job->status === 'completed') {
            return $job;
        }

        if ($job->status === 'processing') {
            return $job;
        }

        $job->forceFill([
            'status' => 'processing',
            'step' => 'starting',
            'attempts' => $job->attempts + 1,
            'started_at' => now(),
            'failed_at' => null,
            'completed_at' => null,
            'error_code' => null,
            'last_error' => null,
        ])->save();

        try {
            DB::transaction(function () use ($job) {
                $lockedJob = TenantProvisioningJob::query()
                    ->whereKey($job->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedJob->status === 'completed') {
                    return;
                }

                $tenant = Tenant::query()
                    ->whereKey($lockedJob->tenant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $payload = $lockedJob->payload ?? [];
                $ownerId = (int) ($payload['owner_user_id'] ?? 0);
                $planId = (int) ($payload['plan_id'] ?? 0);

                $lockedJob->update([
                    'step' => 'validate_configuration',
                ]);

                if ($ownerId < 1 || $planId < 1) {
                    throw ValidationException::withMessages([
                        'provisioning' => [
                            'A valid owner and subscription plan are required.',
                        ],
                    ]);
                }

                $ownerExists = DB::table('users')
                    ->where('id', $ownerId)
                    ->exists();

                if (! $ownerExists) {
                    throw ValidationException::withMessages([
                        'owner_user_id' => ['The selected owner does not exist.'],
                    ]);
                }

                $plan = SubscriptionPlan::query()
                    ->whereKey($planId)
                    ->where('is_active', true)
                    ->first();

                if (! $plan) {
                    throw ValidationException::withMessages([
                        'plan_id' => ['The selected subscription plan is inactive or unavailable.'],
                    ]);
                }

                $completed = $lockedJob->completed_steps ?? [];

                // Step 1: Set the tenant owner and ensure owner membership.
                $lockedJob->update(['step' => 'owner_membership']);

                $membership = $tenant->memberships()
                    ->where('user_id', $ownerId)
                    ->lockForUpdate()
                    ->first();

                $tenant->memberships()
                    ->where('role', 'owner')
                    ->where('user_id', '!=', $ownerId)
                    ->update(['role' => 'admin']);

                if ($membership) {
                    $membership->forceFill([
                        'role' => 'owner',
                        'status' => 'active',
                    ])->save();
                } else {
                    $tenant->memberships()->create([
                        'user_id' => $ownerId,
                        'role' => 'owner',
                        'status' => 'active',
                    ]);
                }

                if ((int) $tenant->owner_user_id !== $ownerId) {
                    $tenant->owner_user_id = $ownerId;
                    $tenant->save();
                }

                $completed[] = 'owner_membership';
                $lockedJob->update([
                    'completed_steps' => array_values(array_unique($completed)),
                ]);

                // Step 2: Ensure the requested subscription exists.
                $lockedJob->update(['step' => 'subscription']);

                $activeSubscription = TenantSubscription::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($activeSubscription) {
                    if (
                        (int) $activeSubscription->subscription_plan_id
                        !== (int) $plan->id
                    ) {
                        throw ValidationException::withMessages([
                            'plan_id' => [
                                'The tenant already has a different active subscription. '
                                . 'Change it through the subscription management workflow first.',
                            ],
                        ]);
                    }
                } else {
                    $startsAt = now();

                    $endsAt = match ($plan->billing_cycle) {
                        'monthly' => $startsAt->copy()->addMonth(),
                        'yearly' => $startsAt->copy()->addYear(),
                        default => null,
                    };

                    $subscription = TenantSubscription::create([
                        'tenant_id' => $tenant->id,
                        'subscription_plan_id' => $plan->id,
                        'status' => 'active',
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);

                    TenantSubscriptionEvent::create([
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                        'event_type' => 'assigned',
                        'old_values' => null,
                        'new_values' => [
                            'subscription_plan_id' => $plan->id,
                            'status' => 'active',
                        ],
                        'reason' => 'Assigned by tenant provisioning.',
                        'performed_by' => $lockedJob->requested_by,
                    ]);
                }

                $completed[] = 'subscription';
                $completed[] = 'completed';

                $lockedJob->update([
                    'status' => 'completed',
                    'step' => 'completed',
                    'completed_steps' => array_values(array_unique($completed)),
                    'completed_at' => now(),
                    'failed_at' => null,
                    'error_code' => null,
                    'last_error' => null,
                ]);
            });

        } catch (Throwable $exception) {
            // The provisioning transaction is rolled back on failure.
            // Keep the failure record outside that transaction.
            $job->refresh();

            $job->forceFill([
                'status' => 'failed',
                'step' => $job->step ?: 'failed',
                'failed_at' => now(),
                'error_code' => class_basename($exception),
                'last_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    5000
                ),
            ])->save();
        }

        return $job->fresh();
    }
}
