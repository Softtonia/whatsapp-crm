<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Tenant $tenant;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->tenant = Tenant::create([
            'company_name' => 'Lifecycle Test Tenant',
            'slug' => 'lifecycle-test-tenant',
            'status' => 'active',
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Lifecycle Test Plan',
            'slug' => 'lifecycle-test-plan',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);
    }

    private function createSubscription(
        string $status = 'active',
        array $overrides = []
    ): TenantSubscription {
        return TenantSubscription::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $this->plan->id,
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ], $overrides));
    }

    public function test_immediate_cancellation_updates_status_and_audit_event(): void
    {
        $subscription = $this->createSubscription();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/cancel",
                [
                    'cancel_at_period_end' => false,
                    'reason' => 'Customer requested cancellation',
                ]
            );

        $response->assertOk()
            ->assertJsonPath(
                'message',
                'Subscription cancelled successfully.'
            );

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
            'cancel_at_period_end' => false,
        ]);

        $this->assertNotNull(
            $subscription->fresh()->cancelled_at
        );

        $this->assertDatabaseHas('tenant_subscription_events', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_cancelled',
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_period_end_cancellation_keeps_subscription_current(): void
    {
        $subscription = $this->createSubscription();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/cancel",
                [
                    'cancel_at_period_end' => true,
                    'reason' => 'Cancel at renewal',
                ]
            );

        $response->assertOk()
            ->assertJsonPath(
                'message',
                'Cancellation scheduled for the end of the billing period.'
            );

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'cancel_at_period_end' => true,
        ]);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'event_type' => 'cancellation_scheduled',
        ]);
    }

    public function test_cancelled_subscription_cannot_be_cancelled_again(): void
    {
        $subscription = $this->createSubscription('cancelled', [
            'cancelled_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/cancel",
                ['cancel_at_period_end' => false]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_reactivation_restores_cancelled_subscription(): void
    {
        $subscription = $this->createSubscription('cancelled', [
            'cancelled_at' => now()->subHour(),
            'ended_at' => now()->subHour(),
            'cancel_at_period_end' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/reactivate"
            );

        $response->assertOk()
            ->assertJsonPath(
                'message',
                'Subscription reactivated successfully.'
            );

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_reactivated',
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_reactivation_is_rejected_when_another_current_subscription_exists(): void
    {
        $cancelledSubscription = $this->createSubscription('cancelled', [
            'cancelled_at' => now()->subHour(),
        ]);

        $currentSubscription = $this->createSubscription();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$cancelledSubscription->id}/reactivate"
            );

        $response->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Another current subscription already exists for this tenant.'
            );

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $cancelledSubscription->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $currentSubscription->id,
            'status' => 'active',
        ]);
    }

    public function test_reactivation_rejects_expired_subscription(): void
    {
        $subscription = $this->createSubscription('cancelled', [
            'ends_at' => now()->subDay(),
            'cancelled_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/reactivate"
            );

        $response->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'An expired subscription cannot be reactivated. Assign a new subscription instead.'
            );

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_tenant_scoped_cancel_rejects_subscription_of_another_tenant(): void
    {
        $otherTenant = Tenant::create([
            'company_name' => 'Another Tenant',
            'slug' => 'another-lifecycle-tenant',
            'status' => 'active',
        ]);

        $subscription = $this->createSubscription();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/tenants/{$otherTenant->id}/subscription/{$subscription->id}/cancel",
                ['cancel_at_period_end' => false]
            );

        $response->assertNotFound();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_cancel_endpoint_rejects_invalid_request_data(): void
    {
        $subscription = $this->createSubscription();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/auth/admin/subscriptions/{$subscription->id}/cancel",
                [
                    'cancel_at_period_end' => 'not-a-boolean',
                    'reason' => str_repeat('x', 1001),
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
        ]);
    }
}
