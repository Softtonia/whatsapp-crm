<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SubscriptionPlan $currentPlan;

    private SubscriptionPlan $targetPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'company_name' => 'Lifecycle Test Tenant',
            'slug' => 'lifecycle-test-tenant',
            'status' => 'active',
        ]);

        $this->currentPlan = $this->createPlan('Current Plan');
        $this->targetPlan = $this->createPlan('Target Plan');
    }

    private function createPlan(
        string $name,
        array $overrides = []
    ): SubscriptionPlan {
        $slug = strtolower(str_replace(' ', '-', $name));

        return SubscriptionPlan::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'code' => strtoupper(str_replace('-', '_', $slug)),
            'description' => 'Lifecycle test plan',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'visibility' => 'public',
            'available_for_signup' => true,
            'trial_days' => 0,
            'sort_order' => 1,
        ], $overrides));
    }

    private function createSubscription(
        array $overrides = []
    ): TenantSubscription {
        return TenantSubscription::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $this->currentPlan->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'billing_period_starts_at' => now()->subMonth(),
            'billing_period_ends_at' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ], $overrides));
    }

    private function schedulePlanChange(
        TenantSubscription $subscription,
        SubscriptionPlan $plan,
        string $effectiveAt
    ): int {
        return DB::table('subscription_change_requests')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'requested_plan_id' => $plan->id,
            'change_type' => 'plan_change',
            'effective_timing' => 'scheduled',
            'status' => 'pending',
            'reason' => 'Lifecycle test',
            'effective_at' => $effectiveAt,
            'metadata' => json_encode([
                'previous_plan_id' => $subscription->subscription_plan_id,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runLifecycleCommand(): void
    {
        $this->assertSame(
            0,
            Artisan::call('subscriptions:process-lifecycle')
        );
    }

    public function test_due_scheduled_plan_change_is_applied(): void
    {
        $subscription = $this->createSubscription();

        $requestId = $this->schedulePlanChange(
            $subscription,
            $this->targetPlan,
            now()->subMinute()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $this->targetPlan->id,
        ]);

        $this->assertDatabaseHas('subscription_change_requests', [
            'id' => $requestId,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'scheduled_plan_change_applied',
        ]);
    }

    public function test_future_scheduled_plan_change_remains_pending(): void
    {
        $subscription = $this->createSubscription();

        $requestId = $this->schedulePlanChange(
            $subscription,
            $this->targetPlan,
            now()->addDay()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $this->currentPlan->id,
        ]);

        $this->assertDatabaseHas('subscription_change_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'scheduled_plan_change_applied',
        ]);
    }

    public function test_inactive_target_plan_is_not_applied(): void
    {
        $subscription = $this->createSubscription();

        $inactivePlan = $this->createPlan('Inactive Plan', [
            'is_active' => false,
        ]);

        $requestId = $this->schedulePlanChange(
            $subscription,
            $inactivePlan,
            now()->subMinute()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $this->currentPlan->id,
        ]);

        $this->assertDatabaseHas('subscription_change_requests', [
            'id' => $requestId,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'scheduled_plan_change_applied',
        ]);
    }

    public function test_archived_target_plan_is_not_applied(): void
    {
        $subscription = $this->createSubscription();

        $archivedPlan = $this->createPlan('Archived Plan', [
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        $requestId = $this->schedulePlanChange(
            $subscription,
            $archivedPlan,
            now()->subMinute()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $this->currentPlan->id,
        ]);

        $this->assertDatabaseHas('subscription_change_requests', [
            'id' => $requestId,
            'status' => 'failed',
        ]);
    }

    public function test_due_period_end_cancellation_is_processed(): void
    {
        $subscription = $this->createSubscription([
            'billing_period_ends_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
            'cancel_at_period_end' => true,
        ]);

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
            'cancel_at_period_end' => false,
        ]);

        $this->assertNotNull(
            $subscription->fresh()->cancelled_at
        );

        $this->assertNotNull(
            $subscription->fresh()->ended_at
        );

        $this->assertDatabaseHas('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_cancelled_at_period_end',
        ]);
    }

    public function test_expired_trial_is_marked_expired_and_audit_event_is_created(): void
    {
        $trialEndsAt = now()->subMinute();

        $subscription = $this->createSubscription([
            'status' => 'trial',
            'trial_starts_at' => now()->subDays(7),
            'trial_ends_at' => $trialEndsAt,
            'ended_at' => null,
        ]);

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'expired',
        ]);

        $this->assertNotNull($subscription->fresh()->ended_at);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_trial_expired',
        ]);

        $this->assertSame(
            1,
            TenantSubscriptionEvent::query()
                ->where('subscription_id', $subscription->id)
                ->where('event_type', 'subscription_trial_expired')
                ->count()
        );
    }

    public function test_future_trial_remains_active_as_trial_without_expiry_event(): void
    {
        $subscription = $this->createSubscription([
            'status' => 'trial',
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(5),
            'ended_at' => null,
        ]);

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'trial',
        ]);

        $this->assertDatabaseMissing('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_trial_expired',
        ]);
    }

    public function test_expired_grace_period_suspends_subscription_and_creates_audit_event(): void
    {
        $subscription = $this->createSubscription([
            'status' => 'past_due',
            'grace_period_ends_at' => now()->subMinute(),
        ]);

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_grace_period_expired',
        ]);

        $this->assertSame(
            1,
            TenantSubscriptionEvent::query()
                ->where('subscription_id', $subscription->id)
                ->where('event_type', 'subscription_grace_period_expired')
                ->count()
        );
    }

    public function test_future_grace_period_keeps_subscription_past_due(): void
    {
        $subscription = $this->createSubscription([
            'status' => 'past_due',
            'grace_period_ends_at' => now()->addDays(3),
        ]);

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'past_due',
        ]);

        $this->assertDatabaseMissing('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'subscription_grace_period_expired',
        ]);
    }

    public function test_repeated_command_does_not_create_duplicate_events(): void
    {
        $subscription = $this->createSubscription([
            'billing_period_ends_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
            'cancel_at_period_end' => true,
        ]);

        $this->runLifecycleCommand();
        $this->runLifecycleCommand();

        $this->assertSame(
            1,
            TenantSubscriptionEvent::query()
                ->where('subscription_id', $subscription->id)
                ->where(
                    'event_type',
                    'subscription_cancelled_at_period_end'
                )
                ->count()
        );
    }

    public function test_scheduled_change_for_cancelled_subscription_is_not_applied(): void
    {
        $subscription = $this->createSubscription([
            'status' => 'cancelled',
            'cancelled_at' => now()->subDay(),
        ]);

        $requestId = $this->schedulePlanChange(
            $subscription,
            $this->targetPlan,
            now()->subMinute()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $this->currentPlan->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('subscription_change_requests', [
            'id' => $requestId,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseMissing('tenant_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'scheduled_plan_change_applied',
        ]);
    }

    public function test_scheduled_plan_change_updates_pricing_and_audit_values(): void
    {
        $this->targetPlan->update([
            'price' => 1499,
            'currency' => 'INR',
            'billing_cycle' => 'yearly',
            'setup_fee' => 99,
        ]);

        $subscription = $this->createSubscription([
            'status' => 'active',
            'base_price' => 999,
            'currency' => 'INR',
            'billing_interval' => 'monthly',
            'setup_fee' => 0,
        ]);

        $requestId = $this->schedulePlanChange(
            $subscription,
            $this->targetPlan,
            now()->subMinute()->toDateTimeString()
        );

        $this->runLifecycleCommand();

        $subscription->refresh();

        $this->assertSame(
            $this->targetPlan->id,
            $subscription->subscription_plan_id
        );
        $this->assertEquals('1499.00', $subscription->base_price);
        $this->assertSame('INR', $subscription->currency);
        $this->assertSame('yearly', $subscription->billing_interval);
        $this->assertEquals('99.00', $subscription->setup_fee);

        $event = TenantSubscriptionEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('event_type', 'scheduled_plan_change_applied')
            ->firstOrFail();

        $this->assertEquals('999.00', $event->old_values['base_price']);
        $this->assertEquals('1499.00', $event->new_values['base_price']);
        $this->assertSame('monthly', $event->old_values['billing_interval']);
        $this->assertSame('yearly', $event->new_values['billing_interval']);
        $this->assertSame($requestId, $event->new_values['change_request_id']);
    }
}
