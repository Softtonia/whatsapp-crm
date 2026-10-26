<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscription\StoreSubscriptionPlanRequest;
use App\Http\Requests\Admin\Subscription\UpdateSubscriptionPlanRequest;
use App\Http\Resources\Admin\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
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

    /**
     * List all subscription plans.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, 'plans.read');
        $query = SubscriptionPlan::query()
            ->withCount('subscriptions')
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->filled('billing_cycle'), function ($q) use ($request) {
                $q->where('billing_cycle', $request->input('billing_cycle'));
            })
            ->orderBy('price')
            ->orderBy('id');

        return SubscriptionPlanResource::collection($query->get());
    }

    /**
     * Create a new subscription plan.
     */
    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $this->authorizePermission($request, 'plans.create');
        $data = $request->validated();

        $name = trim($data['name']);
        $baseSlug = Str::slug($data['slug'] ?? $name);
        $slug = $baseSlug;
        $counter = 1;

        while (SubscriptionPlan::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $data['slug'] = $slug;

        $plan = SubscriptionPlan::create($data);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'data' => new SubscriptionPlanResource($plan),
        ], 201);
    }

    /**
     * View subscription plan details.
     */
    public function show(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.read');
        $plan->loadCount('subscriptions');

        return response()->json([
            'data' => new SubscriptionPlanResource($plan),
        ]);
    }

    /**
     * Update an existing subscription plan.
     */
    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.update');
        $data = $request->validated();

        if (isset($data['name']) && empty($data['slug'])) {
            // Keep existing slug if not provided
            unset($data['slug']);
        }

        $plan->update($data);

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'data' => new SubscriptionPlanResource($plan->fresh()),
        ]);
    }

    /**
     * Safely delete a plan if not assigned to existing tenants, or deactivate it.
     */
    public function destroy(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.archive', 'plans.update');
        $assignedCount = $plan->subscriptions()->count();

        if ($assignedCount > 0) {
            return response()->json([
                'message' => 'Cannot delete subscription plan because it is currently assigned to tenants. Deactivate the plan instead.',
                'subscribers_count' => $assignedCount,
            ], 409);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }

    /**
     * Activate a plan.
     */
    public function activate(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.update');
        $plan->update(['is_active' => true]);

        return response()->json([
            'message' => 'Subscription plan activated successfully.',
            'data' => new SubscriptionPlanResource($plan->fresh()),
        ]);
    }

    /**
     * Deactivate a plan.
     */
    public function deactivate(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.update');
        $plan->update(['is_active' => false]);

        return response()->json([
            'message' => 'Subscription plan deactivated successfully.',
            'data' => new SubscriptionPlanResource($plan->fresh()),
        ]);
    }


    /**
     * Preview the impact of archiving a subscription plan.
     */
    public function archivePreview(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.archive', 'plans.read');
        $subscriptions = $plan->subscriptions();

        $totalCount = (clone $subscriptions)->count();

        $activeCount = (clone $subscriptions)
            ->where('status', 'active')
            ->count();

        $trialCount = (clone $subscriptions)
            ->where('status', 'trial')
            ->count();

        $pastDueCount = (clone $subscriptions)
            ->where('status', 'past_due')
            ->count();

        return response()->json([
            'data' => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'current_status' => $plan->status,
                'already_archived' => $plan->isArchived(),
                'subscriptions' => [
                    'total' => $totalCount,
                    'active' => $activeCount,
                    'trial' => $trialCount,
                    'past_due' => $pastDueCount,
                ],
                'impact' => [
                    'new_signups_disabled' => true,
                    'existing_subscriptions_preserved' => true,
                    'confirmation_required' => $totalCount > 0,
                ],
            ],
        ]);
    }



    /**
     * Archive a subscription plan while preserving subscription history.
     */
    public function archive(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->authorizePermission($request, 'plans.archive');
        if ($plan->isArchived()) {
            return response()->json([
                'message' => 'Subscription plan is already archived.',
            ], 422);
        }

        $request->validate([
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $assignedCount = $plan->subscriptions()
            ->whereIn('status', ['active', 'trial', 'past_due'])
            ->count();

        if ($assignedCount > 0 && !$request->boolean('confirm')) {
            return response()->json([
                'message' => 'This plan has existing subscribers. Confirm before archiving.',
                'confirmation_required' => true,
                'subscribers_count' => $assignedCount,
                'archive_preview_url' => url(
                    "/api/auth/admin/subscriptions/plans/{$plan->id}/archive-preview"
                ),
            ], 409);
        }

        $plan->update([
            'status' => 'archived',
            'is_active' => false,
            'available_for_signup' => false,
            'archived_at' => now(),
        ]);

        return response()->json([
            'message' => 'Subscription plan archived successfully.',
            'subscribers_count' => $assignedCount,
            'existing_subscriptions_preserved' => true,
            'data' => new SubscriptionPlanResource($plan->fresh()),
        ]);
    }

    /**
     * List supported subscription entitlement keys.
     */
    public function features(): JsonResponse
    {
        return response()->json([
            'data' => [
                [
                    'key' => 'max_users',
                    'name' => 'Maximum Users',
                    'type' => 'integer',
                ],
                [
                    'key' => 'max_contacts',
                    'name' => 'Maximum Contacts',
                    'type' => 'integer',
                ],
                [
                    'key' => 'max_whatsapp_numbers',
                    'name' => 'Maximum WhatsApp Numbers',
                    'type' => 'integer',
                ],
                [
                    'key' => 'max_monthly_campaigns',
                    'name' => 'Maximum Monthly Campaigns',
                    'type' => 'integer',
                ],
                [
                    'key' => 'max_monthly_messages',
                    'name' => 'Maximum Monthly Messages',
                    'type' => 'integer',
                ],
                [
                    'key' => 'automation_enabled',
                    'name' => 'Automation',
                    'type' => 'boolean',
                ],
                [
                    'key' => 'white_label_enabled',
                    'name' => 'White Label',
                    'type' => 'boolean',
                ],
                [
                    'key' => 'custom_domain_enabled',
                    'name' => 'Custom Domain',
                    'type' => 'boolean',
                ],
                [
                    'key' => 'api_access_enabled',
                    'name' => 'API Access',
                    'type' => 'boolean',
                ],
            ],
        ]);
    }

    /**
     * Update plan entitlements.
     */
    public function updateEntitlements(
        Request $request,
        SubscriptionPlan $plan
    ): JsonResponse {
        $data = $request->validate([
            'entitlements' => ['required', 'array', 'min:1'],

            'entitlements.max_users' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'entitlements.max_contacts' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'entitlements.max_whatsapp_numbers' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'entitlements.max_monthly_campaigns' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'entitlements.max_monthly_messages' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],

            'entitlements.automation_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'entitlements.white_label_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'entitlements.custom_domain_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'entitlements.api_access_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
        ]);

        $entitlements = $data['entitlements'];

        $legacyFields = [
            'max_users' => 'max_team_members',
            'max_contacts' => 'max_contacts',
            'max_whatsapp_numbers' => 'max_whatsapp_accounts',
            'max_monthly_messages' => 'max_whatsapp_messages',
        ];

        $legacyUpdates = [];

        foreach ($legacyFields as $key => $column) {
            if (array_key_exists($key, $entitlements)) {
                $legacyUpdates[$column] = $entitlements[$key];
            }
        }

        \Illuminate\Support\Facades\DB::transaction(
            function () use ($plan, $entitlements, $legacyUpdates) {
                if ($legacyUpdates !== []) {
                    $plan->update($legacyUpdates);
                }

                foreach ($entitlements as $key => $value) {
                    \App\Models\PlanFeature::updateOrCreate(
                        [
                            'plan_id' => $plan->id,
                            'feature_key' => $key,
                        ],
                        [
                            'version_id' => null,
                            'feature_name' => str_replace(
                                '_',
                                ' ',
                                ucfirst($key)
                            ),
                            'feature_type' => is_bool($value)
                                ? 'boolean'
                                : 'integer',
                            'value' => $value,
                        ]
                    );
                }
            }
        );

        return response()->json([
            'message' => 'Plan entitlements updated successfully.',
            'data' => [
                'plan_id' => $plan->id,
                'entitlements' => $entitlements,
            ],
        ]);
    }
}
