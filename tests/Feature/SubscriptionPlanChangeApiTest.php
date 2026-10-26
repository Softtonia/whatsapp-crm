<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionPlanChangeApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected Tenant $tenant;

    protected SubscriptionPlan $currentPlan;

    protected SubscriptionPlan $targetPlan;

    protected TenantSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('agent', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $this->superAdmin->assignRole('Super Admin');

        $this->regularUser = User::factory()->create([
            'email' => 'regular@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $this->tenant = Tenant::create([
            'company_name' => 'Plan Change Test Tenant',
            'slug' => 'plan-change-test-tenant',
            'status' => 'active',
        ]);

        $this->currentPlan = $this->createPlan([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'price' => 499,
            'setup_fee' => 50,
        ]);

        $this->targetPlan = $this->createPlan([
            'name' => 'Professional Plan',
            'slug' => 'professional-plan',
            'price' => 1499,
            'setup_fee' => 100,
            'billing_cycle' => 'yearly',
        ]);

        $this->subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $this->currentPlan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'billing_interval' => $this->currentPlan->billing_cycle,
            'currency' => $this->currentPlan->currency,
            'base_price' => $this->currentPlan->price,
            'setup_fee' => $this->currentPlan->setup_fee ?? 0,
            'cancel_at_period_end' => false,
        ]);
    }

    private function createPlan(array $overrides = []): SubscriptionPlan
    {
        static $counter = 0;
        $counter++;

        return SubscriptionPlan::create(array_merge([
            'name' => "Test Plan {$counter}",
            'slug' => "test-plan-{$counter}",
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'setup_fee' => 0,
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
            'archived_at' => null,
        ], $overrides));
    }

    private function changePlanUrl(): string
    {
        return "/api/auth/admin/subscriptions/{$this->subscription->id}/change-plan";
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'subscription_plan_id' => $this->targetPlan->id,
            'effective_timing' => 'immediate',
            'reason' => 'Plan change test',
        ], $overrides);
    }

    private function actingAsSuperAdmin(): static
    {
        return $this->actingAs($this->superAdmin, 'sanctum');
    }

    public function test_immediate_plan_change_updates_pricing_and_creates_audit_event(): void
    {
        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload()
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription plan changed successfully.');

        $this->subscription->refresh();

        $this->assertSame(
            (int) $this->targetPlan->id,
            (int) $this->subscription->subscription_plan_id
        );

        $this->assertEquals(
            (string) $this->targetPlan->price,
            (string) $this->subscription->base_price
        );

        $this->assertSame(
            $this->targetPlan->currency,
            $this->subscription->currency
        );

        $this->assertSame(
            $this->targetPlan->billing_cycle,
            $this->subscription->billing_interval
        );

        $this->assertEquals(
            (string) ($this->targetPlan->setup_fee ?? 0),
            (string) $this->subscription->setup_fee
        );

        $this->assertDatabaseHas('tenant_subscription_events', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $this->subscription->id,
            'event_type' => 'plan_changed',
            'source' => 'admin',
        ]);
    }

    public function test_scheduled_plan_change_creates_pending_request_without_changing_current_plan(): void
    {
        $effectiveAt = now()->addDays(5)->toDateTimeString();

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'effective_timing' => 'scheduled',
                'effective_at' => $effectiveAt,
            ])
        );

        $response->assertStatus(202)
            ->assertJsonPath(
                'message',
                'Plan change scheduled successfully.'
            )
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath(
                'data.requested_plan_id',
                $this->targetPlan->id
            );

        $this->subscription->refresh();

        $this->assertSame(
            (int) $this->currentPlan->id,
            (int) $this->subscription->subscription_plan_id
        );

        $this->assertDatabaseHas('subscription_change_requests', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $this->subscription->id,
            'requested_plan_id' => $this->targetPlan->id,
            'change_type' => 'plan_change',
            'effective_timing' => 'scheduled',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $this->subscription->id,
            'event_type' => 'plan_change_scheduled',
            'source' => 'admin',
        ]);
    }

    public function test_duplicate_pending_plan_change_returns_conflict(): void
    {
        DB::table('subscription_change_requests')->insert([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $this->subscription->id,
            'requested_plan_id' => $this->targetPlan->id,
            'change_type' => 'plan_change',
            'effective_timing' => 'scheduled',
            'status' => 'pending',
            'reason' => 'Existing request',
            'requested_by' => $this->superAdmin->id,
            'effective_at' => now()->addDays(5),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'effective_timing' => 'scheduled',
                'effective_at' => now()->addDays(10)->toDateTimeString(),
            ])
        );

        $response->assertStatus(409)
            ->assertJsonPath(
                'message',
                'A pending plan change already exists for this subscription.'
            );

        $this->assertSame(
            1,
            DB::table('subscription_change_requests')
                ->where('subscription_id', $this->subscription->id)
                ->where('status', 'pending')
                ->count()
        );
    }

    public function test_same_plan_is_rejected(): void
    {
        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'subscription_plan_id' => $this->currentPlan->id,
            ])
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The subscription already uses this plan.'
            );
    }

    public function test_inactive_plan_is_rejected(): void
    {
        $plan = $this->createPlan([
            'name' => 'Inactive Plan',
            'slug' => 'inactive-plan',
            'is_active' => false,
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'subscription_plan_id' => $plan->id,
            ])
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The selected plan is not available.'
            );
    }

    public function test_unpublished_plan_is_rejected(): void
    {
        $plan = $this->createPlan([
            'name' => 'Draft Plan',
            'slug' => 'draft-plan',
            'status' => 'draft',
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'subscription_plan_id' => $plan->id,
            ])
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The selected plan is not available.'
            );
    }

    public function test_archived_plan_is_rejected(): void
    {
        $plan = $this->createPlan([
            'name' => 'Archived Plan',
            'slug' => 'archived-plan',
            'archived_at' => now(),
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'subscription_plan_id' => $plan->id,
            ])
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The selected plan is not available.'
            );
    }

    public function test_plan_unavailable_for_signup_is_rejected(): void
    {
        $plan = $this->createPlan([
            'name' => 'Unavailable Plan',
            'slug' => 'unavailable-plan',
            'available_for_signup' => false,
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'subscription_plan_id' => $plan->id,
            ])
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'The selected plan is not available.'
            );
    }

    public function test_cancelled_subscription_cannot_change_plan(): void
    {
        $this->subscription->update([
            'status' => 'cancelled',
        ]);

        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload()
        );

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Only current subscriptions can change plans.'
            );
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            [
                'subscription_plan_id' => 999999,
                'effective_timing' => 'later',
                'effective_at' => now()->subDay()->toDateTimeString(),
                'reason' => str_repeat('x', 1001),
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'subscription_plan_id',
                'effective_timing',
                'effective_at',
                'reason',
            ]);
    }

    public function test_scheduled_change_requires_future_effective_at(): void
    {
        $response = $this->actingAsSuperAdmin()->postJson(
            $this->changePlanUrl(),
            $this->validPayload([
                'effective_timing' => 'scheduled',
                'effective_at' => now()->subDay()->toDateTimeString(),
            ])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_at']);
    }

    public function test_guest_cannot_change_subscription_plan(): void
    {
        $response = $this->postJson(
            $this->changePlanUrl(),
            $this->validPayload()
        );

        $response->assertUnauthorized();
    }

    public function test_regular_user_cannot_change_subscription_plan(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->postJson(
                $this->changePlanUrl(),
                $this->validPayload()
            );

        $response->assertForbidden();
    }
}