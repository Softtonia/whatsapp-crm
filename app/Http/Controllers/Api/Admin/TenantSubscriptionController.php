<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscription\AssignTenantSubscriptionRequest;
use App\Http\Requests\Admin\Subscription\UpdateTenantSubscriptionRequest;
use App\Http\Resources\Admin\TenantSubscriptionResource;
use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantSubscriptionController extends Controller
{
    protected function authorizePermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists()) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission) || $user->hasPermissionTo($permission, 'web')) {
                return;
            }
        }

        abort(403, "You do not have the required permission: {$permissions[0]}.");
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'subscriptions.read', 'tenants.subscriptions.view');
        $filters = $request->validate([
            'tenant_id' => ['sometimes', 'integer', 'exists:tenants,id'],
            'plan_id' => ['sometimes', 'integer', 'exists:subscription_plans,id'],
            'status' => [
                'sometimes',
                'string',
                \Illuminate\Validation\Rule::in([
                    'trial',
                    'active',
                    'past_due',
                    'expired',
                    'cancelled',
                    'suspended',
                ]),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TenantSubscription::query()
            ->with(['plan', 'tenant'])
            ->when(
                isset($filters['tenant_id']),
                fn($query) => $query->where('tenant_id', $filters['tenant_id'])
            )
            ->when(
                isset($filters['plan_id']),
                fn($query) => $query->where('subscription_plan_id', $filters['plan_id'])
            )
            ->when(
                isset($filters['status']),
                fn($query) => $query->where('status', $filters['status'])
            )
            ->orderByDesc('id');

        return response()->json(
            TenantSubscriptionResource::collection(
                $query->paginate($filters['per_page'] ?? 15)
            )->response()->getData(true)
        );
    }

    public function events(
        Request $request,
        TenantSubscription $subscription
    ): JsonResponse {
        $events = TenantSubscriptionEvent::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Get subscription details, history, and usage metrics for a tenant.
     */
    public function show(Request $request, Tenant $tenant, SubscriptionUsageService $usageService): JsonResponse
    {
        $this->authorizeTenantAccess($request->user(), $tenant);

        $summary = $usageService->getSubscriptionSummary($tenant->id);

        $history = TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'business_id' => $tenant->id,
                'company_name' => $tenant->company_name ?? $tenant->name,
                'has_active_subscription' => $summary['has_active_subscription'],
                'current_subscription' => $summary['subscription'] ? new TenantSubscriptionResource($summary['subscription']) : null,
                'billing_period' => $summary['billing_period'],
                'usage' => $summary['usage'],
                'history' => TenantSubscriptionResource::collection($history),
            ],
        ]);
    }

    /**
     * Assign a subscription plan to a tenant (Super Admin only).
     */
    public function assign(
        AssignTenantSubscriptionRequest $request,
        Tenant $tenant
    ): JsonResponse {
        $this->authorizePermission($request, 'subscriptions.assign', 'tenants.subscriptions.create');
        $data = $request->validated();

        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->where('status', 'published')
            ->where('available_for_signup', true)
            ->whereNull('archived_at')
            ->findOrFail($data['subscription_plan_id']);

        $result = DB::transaction(function () use (
            $request,
            $tenant,
            $plan,
            $data
        ) {
            $existingSubscriptions = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['active', 'trial', 'past_due'])
                ->lockForUpdate()
                ->get();

            foreach ($existingSubscriptions as $existing) {
                $oldValues = $existing->only([
                    'subscription_plan_id',
                    'status',
                    'cancelled_at',
                    'ended_at',
                ]);

                $existing->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'ended_at' => now(),
                    'cancel_at_period_end' => false,
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $existing->id,
                    'event_type' => 'subscription_replaced',
                    'old_values' => $oldValues,
                    'new_values' => $existing->fresh()->only([
                        'subscription_plan_id',
                        'status',
                        'cancelled_at',
                        'ended_at',
                    ]),
                    'reason' => 'Subscription replaced by a newly assigned plan.',
                    'performed_by' => $request->user()?->id,
                    'source' => 'admin',
                ]);
            }

            $startsAt = ! empty($data['starts_at'])
                ? Carbon::parse($data['starts_at'])
                : now();

            $endsAt = ! empty($data['ends_at'])
                ? Carbon::parse($data['ends_at'])
                : match ($plan->billing_cycle) {
                    'yearly' => $startsAt->copy()->addYear(),
                    'monthly' => $startsAt->copy()->addMonth(),
                    default => null,
                };

            $status = $data['status'] ?? 'active';

            $subscription = TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'trial_starts_at' => $status === 'trial'
                    ? $startsAt
                    : null,
                'trial_ends_at' => ! empty($data['trial_ends_at'])
                    ? Carbon::parse($data['trial_ends_at'])
                    : null,
                'billing_period_starts_at' => $startsAt,
                'billing_period_ends_at' => $endsAt,
                'next_billing_at' => $endsAt,
                'billing_interval' => $plan->billing_cycle,
                'currency' => $plan->currency ?? 'INR',
                'base_price' => $plan->price,
                'setup_fee' => $plan->setup_fee ?? 0,
                'assigned_by' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'event_type' => 'subscription_assigned',
                'old_values' => null,
                'new_values' => [
                    'subscription_plan_id' => $plan->id,
                    'status' => $subscription->status,
                    'base_price' => $subscription->base_price,
                    'currency' => $subscription->currency,
                ],
                'reason' => $data['notes'] ?? 'Subscription assigned by admin.',
                'performed_by' => $request->user()?->id,
                'source' => 'admin',
            ]);

            return $subscription;
        });

        $result->load(['plan', 'tenant']);

        return response()->json([
            'message' => 'Subscription plan assigned successfully.',
            'data' => new TenantSubscriptionResource($result),
        ], 201);
    }

    /**
     * Update an existing subscription (dates, plan, status).
     */
    public function update(UpdateTenantSubscriptionRequest $request, Tenant $tenant, TenantSubscription $subscription): JsonResponse
    {
        $this->authorizePermission($request, 'subscriptions.update', 'tenants.subscriptions.update');
        if ($subscription->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Subscription does not belong to this business.'], 404);
        }

        $data = $request->validated();

        if (isset($data['status']) && $data['status'] === 'cancelled' && empty($subscription->cancelled_at)) {
            $data['cancelled_at'] = now();
        }

        $subscription->update($data);

        $subscription->load(['plan', 'tenant']);

        return response()->json([
            'message' => 'Subscription updated successfully.',
            'data' => new TenantSubscriptionResource($subscription->fresh()),
        ]);
    }

    /**
     * Cancel an active subscription.
     */
    public function cancel(Request $request, Tenant $tenant, TenantSubscription $subscription): JsonResponse
    {
        $this->authorizePermission($request, 'subscriptions.cancel', 'tenants.subscriptions.cancel');
        if ($subscription->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Subscription does not belong to this business.'], 404);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Subscription cancelled successfully.',
            'data' => new TenantSubscriptionResource($subscription->fresh()),
        ]);
    }

    /**
     * Authorize user access to tenant data.
     */
    private function authorizeTenantAccess(?\App\Models\User $user, Tenant $tenant): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists()) {
            return;
        }

        $isMember = $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(403, 'Forbidden. You do not have access to this tenant.');
        }
    }

    public function changePlan(
        Request $request,
        TenantSubscription $subscription
    ): JsonResponse {
        $this->authorizePermission($request, 'subscriptions.update', 'tenants.subscriptions.update');
        $data = $request->validate([
            'subscription_plan_id' => [
                'required',
                'integer',
                'exists:subscription_plans,id',
            ],
            'effective_timing' => [
                'required',
                'in:immediate,scheduled',
            ],
            'effective_at' => [
                'required_if:effective_timing,scheduled',
                'nullable',
                'date',
                'after:now',
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $subscriptionId = $subscription->id;

        $result = DB::transaction(function () use (
            $request,
            $subscriptionId,
            $data
        ) {
            // Lock tenant first to serialize subscription mutations.
            $tenantId = TenantSubscription::query()
                ->whereKey($subscriptionId)
                ->value('tenant_id');

            abort_if($tenantId === null, 404, 'Subscription not found.');

            $tenant = Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $subscription = TenantSubscription::query()
                ->whereKey($subscriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array(
                $subscription->status,
                ['active', 'trial', 'past_due'],
                true
            )) {
                return [
                    'error' => 'Only current subscriptions can change plans.',
                    'status' => 422,
                ];
            }

            $plan = SubscriptionPlan::query()
                ->where('is_active', true)
                ->where('status', 'published')
                ->where('available_for_signup', true)
                ->whereNull('archived_at')
                ->find($data['subscription_plan_id']);

            if (! $plan) {
                return [
                    'error' => 'The selected plan is not available.',
                    'status' => 422,
                ];
            }

            if ((int) $plan->id === (int) $subscription->subscription_plan_id) {
                return [
                    'error' => 'The subscription already uses this plan.',
                    'status' => 422,
                ];
            }

            $oldValues = [
                'subscription_plan_id' => $subscription->subscription_plan_id,
                'status' => $subscription->status,
                'base_price' => $subscription->base_price,
                'currency' => $subscription->currency,
                'billing_interval' => $subscription->billing_interval,
                'setup_fee' => $subscription->setup_fee,
            ];

            if ($data['effective_timing'] === 'scheduled') {
                $pendingExists = DB::table('subscription_change_requests')
                    ->where('subscription_id', $subscription->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($pendingExists) {
                    return [
                        'error' => 'A pending plan change already exists for this subscription.',
                        'status' => 409,
                    ];
                }

                DB::table('subscription_change_requests')->insert([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'requested_plan_id' => $plan->id,
                    'change_type' => 'plan_change',
                    'effective_timing' => 'scheduled',
                    'status' => 'pending',
                    'reason' => $data['reason'] ?? null,
                    'requested_by' => $request->user()?->id,
                    'effective_at' => $data['effective_at'],
                    'metadata' => json_encode([
                        'previous_plan_id' => $subscription->subscription_plan_id,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                TenantSubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => 'plan_change_scheduled',
                    'old_values' => $oldValues,
                    'new_values' => [
                        'subscription_plan_id' => $plan->id,
                        'effective_at' => $data['effective_at'],
                    ],
                    'reason' => $data['reason'] ?? null,
                    'performed_by' => $request->user()?->id,
                    'source' => 'admin',
                ]);

                return [
                    'scheduled' => true,
                    'subscription_id' => $subscription->id,
                    'requested_plan_id' => $plan->id,
                    'effective_at' => $data['effective_at'],
                ];
            }

            // Apply the plan and its pricing snapshot together.
            $subscription->update([
                'subscription_plan_id' => $plan->id,
                'base_price' => $plan->price,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_cycle,
                'setup_fee' => $plan->setup_fee ?? 0,
            ]);

            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'event_type' => 'plan_changed',
                'old_values' => $oldValues,
                'new_values' => [
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'status' => $subscription->status,
                    'base_price' => $subscription->base_price,
                    'currency' => $subscription->currency,
                    'billing_interval' => $subscription->billing_interval,
                    'setup_fee' => $subscription->setup_fee,
                ],
                'reason' => $data['reason'] ?? null,
                'performed_by' => $request->user()?->id,
                'source' => 'admin',
            ]);

            return [
                'scheduled' => false,
                'subscription' => $subscription->fresh()->load(['plan', 'tenant']),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        if ($result['scheduled']) {
            return response()->json([
                'message' => 'Plan change scheduled successfully.',
                'data' => [
                    'subscription_id' => $result['subscription_id'],
                    'requested_plan_id' => $result['requested_plan_id'],
                    'effective_at' => $result['effective_at'],
                    'status' => 'pending',
                ],
            ], 202);
        }

        return response()->json([
            'message' => 'Subscription plan changed successfully.',
            'data' => new TenantSubscriptionResource($result['subscription']),
        ]);
    }


    public function cancelSubscription(
        Request $request,
        TenantSubscription $subscription
    ): JsonResponse {
        $data = $request->validate([
            'cancel_at_period_end' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $atPeriodEnd = (bool) ($data['cancel_at_period_end'] ?? false);

        $result = DB::transaction(function () use (
            $request,
            $subscription,
            $data,
            $atPeriodEnd
        ) {
            // Lock tenant first to serialize subscription lifecycle operations.
            $tenant = Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->first();

            if (! $tenant) {
                return [
                    'error' => 'Tenant not found.',
                    'status' => 404,
                ];
            }

            // Reload and lock the subscription inside the transaction.
            $lockedSubscription = TenantSubscription::query()
                ->whereKey($subscription->id)
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSubscription) {
                return [
                    'error' => 'Subscription not found for this tenant.',
                    'status' => 404,
                ];
            }

            if (! in_array(
                $lockedSubscription->status,
                ['active', 'trial', 'past_due'],
                true
            )) {
                return [
                    'error' => 'Only current subscriptions can be cancelled.',
                    'status' => 422,
                ];
            }

            $periodEnd = $lockedSubscription->billing_period_ends_at
                ?? $lockedSubscription->ends_at;

            if ($atPeriodEnd && ! $periodEnd) {
                return [
                    'error' => 'A billing period end date is required for period-end cancellation.',
                    'status' => 422,
                ];
            }

            $oldValues = $lockedSubscription->only([
                'status',
                'cancel_at_period_end',
                'cancelled_at',
                'ended_at',
            ]);

            $updates = [
                'cancel_at_period_end' => $atPeriodEnd,
            ];

            if ($atPeriodEnd) {
                // Keep the subscription current until its billing period ends.
                $updates['cancelled_at'] = null;
            } else {
                // Cancel immediately.
                $updates['status'] = 'cancelled';
                $updates['cancelled_at'] = now();
                $updates['ended_at'] = now();
                $updates['cancel_at_period_end'] = false;
            }

            $lockedSubscription->update($updates);
            $lockedSubscription->refresh();

            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $lockedSubscription->id,
                'event_type' => $atPeriodEnd
                    ? 'cancellation_scheduled'
                    : 'subscription_cancelled',
                'old_values' => $oldValues,
                'new_values' => $lockedSubscription->only([
                    'status',
                    'cancel_at_period_end',
                    'cancelled_at',
                    'ended_at',
                ]),
                'reason' => $data['reason'] ?? null,
                'performed_by' => $request->user()?->id,
                'source' => 'admin',
            ]);

            return [
                'subscription' => $lockedSubscription
                    ->fresh()
                    ->load(['plan', 'tenant']),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'message' => $atPeriodEnd
                ? 'Cancellation scheduled for the end of the billing period.'
                : 'Subscription cancelled successfully.',
            'data' => new TenantSubscriptionResource(
                $result['subscription']
            ),
        ]);
    }



    public function reactivate(
        Request $request,
        TenantSubscription $subscription
    ): JsonResponse {
        $result = DB::transaction(function () use ($request, $subscription) {
            // Lock tenant first so assignments and reactivations serialize.
            $tenant = Tenant::query()
                ->whereKey($subscription->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedSubscription = TenantSubscription::query()
                ->whereKey($subscription->id)
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array(
                $lockedSubscription->status,
                ['active', 'trial', 'past_due', 'cancelled'],
                true
            )) {
                return ['error' => 'This subscription cannot be reactivated.', 'status' => 422];
            }

            if ($lockedSubscription->ends_at && $lockedSubscription->ends_at->isPast()) {
                return [
                    'error' => 'An expired subscription cannot be reactivated. Assign a new subscription instead.',
                    'status' => 422,
                ];
            }

            $hasOtherCurrentSubscription = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $lockedSubscription->id)
                ->whereIn('status', ['active', 'trial', 'past_due'])
                ->exists();

            if ($hasOtherCurrentSubscription) {
                return [
                    'error' => 'Another current subscription already exists for this tenant.',
                    'status' => 409,
                ];
            }

            $oldValues = $lockedSubscription->only([
                'subscription_plan_id',
                'status',
                'cancel_at_period_end',
                'cancelled_at',
                'ended_at',
            ]);

            $newStatus = $lockedSubscription->trial_ends_at?->isFuture()
                ? 'trial'
                : 'active';

            $lockedSubscription->update([
                'status' => $newStatus,
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'ended_at' => null,
            ]);

            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $lockedSubscription->id,
                'event_type' => 'subscription_reactivated',
                'old_values' => $oldValues,
                'new_values' => $lockedSubscription->fresh()->only([
                    'subscription_plan_id',
                    'status',
                    'cancel_at_period_end',
                    'cancelled_at',
                    'ended_at',
                ]),
                'reason' => 'Subscription reactivated',
                'performed_by' => $request->user()?->id,
                'source' => 'admin',
            ]);

            return [
                'subscription' => $lockedSubscription->fresh()->load(['plan', 'tenant']),
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Subscription reactivated successfully.',
            'data' => new TenantSubscriptionResource($result['subscription']),
        ]);
    }
}
