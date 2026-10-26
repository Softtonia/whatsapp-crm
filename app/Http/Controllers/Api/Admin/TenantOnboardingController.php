<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantOnboardingController extends Controller
{
    /**
     * GET /api/auth/admin/tenants/{tenant}/onboarding
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->stateFor($tenant),
        ]);
    }

    /**
     * POST /api/auth/admin/tenants/{tenant}/onboarding
     */
    public function update(
        Request $request,
        Tenant $tenant
    ): JsonResponse {
        $data = $request->validate([
            'business_profile_completed' => [
                'sometimes',
                'boolean',
            ],
            'owner_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'plan_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('subscription_plans', 'id')
                    ->where(
                        fn($query) => $query
                            ->where('is_active', true)
                            ->where('status', 'published')
                            ->where('available_for_signup', true)
                            ->whereNull('archived_at')
                            ->whereNull('deleted_at')
                    ),
            ],
            'complete_onboarding' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $updatedTenant = DB::transaction(function () use (
            $request,
            $tenant,
            $data
        ) {
            $lockedTenant = Tenant::query()
                ->lockForUpdate()
                ->findOrFail($tenant->id);

            $oldValues = [
                'owner_user_id' => $lockedTenant->owner_user_id,
                'onboarding_status' =>
                $lockedTenant->onboarding_status ?? 'not_started',
                'onboarding_completed_at' =>
                $lockedTenant->onboarding_completed_at,
            ];

            // Validate business profile before acknowledging completion.
            if (
                ($data['business_profile_completed'] ?? false)
                && ! $this->businessProfileIsComplete($lockedTenant)
            ) {
                throw ValidationException::withMessages([
                    'business_profile_completed' => [
                        'Company name, email and contact number are required.',
                    ],
                ]);
            }

            // Assign tenant owner and ensure active membership.
            if (
                array_key_exists('owner_user_id', $data)
                && $data['owner_user_id'] !== null
            ) {
                $ownerId = (int) $data['owner_user_id'];

                $membership = $lockedTenant->memberships()
                    ->where('user_id', $ownerId)
                    ->lockForUpdate()
                    ->first();

                $lockedTenant->memberships()
                    ->where('role', 'owner')
                    ->where('user_id', '!=', $ownerId)
                    ->update(['role' => 'admin']);

                if ($membership) {
                    $membership->forceFill([
                        'role' => 'owner',
                        'status' => 'active',
                    ])->save();
                } else {
                    $lockedTenant->memberships()->create([
                        'user_id' => $ownerId,
                        'role' => 'owner',
                        'status' => 'active',
                    ]);
                }

                $lockedTenant->owner_user_id = $ownerId;
                $lockedTenant->save();
            }

            // Assign a subscription plan if requested.
            if (
                array_key_exists('plan_id', $data)
                && $data['plan_id'] !== null
            ) {
                $plan = SubscriptionPlan::query()
                    ->where('is_active', true)
                    ->where('status', 'published')
                    ->where('available_for_signup', true)
                    ->whereNull('archived_at')
                    ->findOrFail($data['plan_id']);

                $now = now();

                $activeSubscriptions = TenantSubscription::query()
                    ->where('tenant_id', $lockedTenant->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeSubscriptions as $oldSubscription) {
                    $oldSubscription->forceFill([
                        'status' => 'cancelled',
                        'cancelled_at' => $now,
                    ])->save();

                    TenantSubscriptionEvent::create([
                        'tenant_id' => $lockedTenant->id,
                        'subscription_id' => $oldSubscription->id,
                        'event_type' => 'cancelled',
                        'old_values' => ['status' => 'active'],
                        'new_values' => [
                            'status' => 'cancelled',
                            'cancelled_at' => $now->toISOString(),
                        ],
                        'reason' => 'Replaced during tenant onboarding.',
                        'performed_by' => $request->user()?->id,
                    ]);
                }

                $startsAt = $now->copy();

                $endsAt = match ($plan->billing_cycle) {
                    'monthly' => $startsAt->copy()->addMonth(),
                    'yearly' => $startsAt->copy()->addYear(),
                    default => null,
                };

                $subscription = TenantSubscription::create([
                    'tenant_id' => $lockedTenant->id,
                    'subscription_plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $lockedTenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => 'assigned',
                    'old_values' => null,
                    'new_values' => [
                        'subscription_plan_id' => $plan->id,
                        'status' => 'active',
                    ],
                    'reason' => 'Assigned during tenant onboarding.',
                    'performed_by' => $request->user()?->id,
                ]);
            }

            $lockedTenant->refresh();
            $state = $this->stateFor($lockedTenant);

            // Completion is allowed only when all steps are complete.
            if (($data['complete_onboarding'] ?? false) === true) {
                $pending = collect($state['steps'])
                    ->where('status', '!=', 'completed')
                    ->pluck('key')
                    ->values()
                    ->all();

                if ($pending !== []) {
                    throw ValidationException::withMessages([
                        'complete_onboarding' => [
                            'Complete these steps first: '
                                . implode(', ', $pending),
                        ],
                    ]);
                }

                $lockedTenant->forceFill([
                    'onboarding_status' => 'completed',
                    'onboarding_completed_at' =>
                    $lockedTenant->onboarding_completed_at ?? now(),
                ])->save();
            } elseif (
                ($lockedTenant->onboarding_status ?? 'not_started')
                !== 'completed'
            ) {
                $hasProgress = collect($state['steps'])
                    ->contains(
                        fn(array $step) =>
                        $step['status'] === 'completed'
                    );

                $lockedTenant->forceFill([
                    'onboarding_status' => $hasProgress
                        ? 'in_progress'
                        : 'not_started',
                    'onboarding_completed_at' => null,
                ])->save();
            }

            $lockedTenant->refresh();

            // Record the change in the existing tenant audit log.
            TenantActivityLog::create([
                'tenant_id' => $lockedTenant->id,
                'actor_user_id' => $request->user()?->id,
                'actor_type' => 'user',
                'event_type' => 'tenant.onboarding.updated',
                'description' => 'Tenant onboarding updated.',
                'subject_type' => Tenant::class,
                'subject_id' => $lockedTenant->id,
                'old_values' => $oldValues,
                'new_values' => [
                    'owner_user_id' => $lockedTenant->owner_user_id,
                    'onboarding_status' =>
                    $lockedTenant->onboarding_status,
                    'onboarding_completed_at' =>
                    $lockedTenant->onboarding_completed_at
                        ?->toISOString(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $lockedTenant;
        });

        return response()->json([
            'success' => true,
            'message' => 'Tenant onboarding updated successfully.',
            'data' => $this->stateFor($updatedTenant),
        ]);
    }

    /**
     * Build checklist from existing tenant-related records.
     */
    private function stateFor(Tenant $tenant): array
    {
        $tenant->loadMissing([
            'memberships',
            'subscriptions',
            'whatsappAccounts',
            'healthChecks',
        ]);

        $hasOwner = $tenant->owner_user_id !== null
            && $tenant->memberships->contains(
                fn(TenantUser $membership) =>
                (int) $membership->user_id
                    === (int) $tenant->owner_user_id
                    && $membership->role === 'owner'
                    && $membership->status === 'active'
            );

        $hasSubscription = $tenant->subscriptions->contains(
            fn(TenantSubscription $subscription) =>
            $subscription->status === 'active'
                && (
                    $subscription->ends_at === null
                    || $subscription->ends_at->isFuture()
                )
                && $subscription->starts_at?->isPast()
        );

        $hasIntegration = $tenant->whatsappAccounts->contains(
            fn($account) => $account->status === 'active'
        ) || $tenant->healthChecks->contains(
            fn($check) =>
            in_array(
                strtolower($check->category),
                ['integration', 'integrations', 'whatsapp'],
                true
            )
                && $check->status === 'healthy'
        );

        $steps = [
            [
                'key' => 'business_profile',
                'status' => $this->businessProfileIsComplete($tenant)
                    ? 'completed'
                    : 'pending',
            ],
            [
                'key' => 'owner',
                'status' => $hasOwner ? 'completed' : 'pending',
            ],
            [
                'key' => 'subscription',
                'status' => $hasSubscription ? 'completed' : 'pending',
            ],
            [
                'key' => 'integrations',
                'status' => $hasIntegration ? 'completed' : 'pending',
            ],
        ];

        $allComplete = collect($steps)->every(
            fn(array $step) => $step['status'] === 'completed'
        );

        $status = $tenant->onboarding_status ?: 'not_started';

        if ($status !== 'completed') {
            $status = collect($steps)->contains(
                fn(array $step) => $step['status'] === 'completed'
            ) ? 'in_progress' : 'not_started';
        } elseif (! $allComplete) {
            $status = 'in_progress';
        }

        return [
            'tenant_id' => (int) $tenant->id,
            'onboarding_status' => $status,
            'onboarding_completed_at' =>
            $status === 'completed'
                ? $tenant->onboarding_completed_at
                : null,
            'steps' => $steps,
        ];
    }

    private function businessProfileIsComplete(Tenant $tenant): bool
    {
        return filled($tenant->company_name)
            && filled($tenant->email)
            && filled($tenant->contact_number);
    }
}
