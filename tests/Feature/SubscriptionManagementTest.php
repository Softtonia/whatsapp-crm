<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantWhatsAppAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\TenantUsageRecord;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\AiAssistantService;
use App\Services\SubscriptionUsageService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularUser;
    protected Tenant $tenant;

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
            'company_name' => 'Apex Solutions',
            'slug' => 'apex-solutions',
            'status' => 'active',
        ]);
    }

    public function test_super_admin_can_crud_subscription_plans(): void
    {
        // 1. Create plan
        $createResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/subscriptions/plans', [
                'name' => 'Pro Plan',
                'code' => 'PRO-PLAN',
                'description' => 'Professional tier for growing businesses.',
                'price' => 2999.00,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'max_whatsapp_messages' => 1000,
                'max_ai_replies' => 500,
                'max_team_members' => 5,
                'max_whatsapp_accounts' => 2,
                'max_contacts' => 5000,
            ]);

        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('data.name', 'Pro Plan');
        $createResponse->assertJsonPath('data.slug', 'pro-plan');
        $createResponse->assertJsonPath('data.limits.max_whatsapp_messages', 1000);

        $planId = $createResponse->json('data.id');

        // 2. List plans
        $listResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/subscriptions/plans');
        $listResponse->assertStatus(200);
        $listResponse->assertJsonCount(1, 'data');

        // 3. Show plan
        $showResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/subscriptions/plans/{$planId}");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.name', 'Pro Plan');

        // 4. Update plan
        $updateResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/auth/admin/subscriptions/plans/{$planId}", [
                'name' => 'Pro Plan Plus',
                'price' => 3499.00,
                'max_whatsapp_messages' => 2000,
            ]);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.name', 'Pro Plan Plus');
        $updateResponse->assertJsonPath('data.limits.max_whatsapp_messages', 2000);

        // 5. Deactivate plan
        $deactivateResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/subscriptions/plans/{$planId}/deactivate");
        $deactivateResponse->assertStatus(200);
        $deactivateResponse->assertJsonPath('data.is_active', false);

        // 6. Delete plan
        $deleteResponse = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/auth/admin/subscriptions/plans/{$planId}");
        $deleteResponse->assertStatus(200);
        $this->assertDatabaseHas('subscription_plans', [
            'id' => $planId,
        ]);

        $this->assertSoftDeleted('subscription_plans', [
            'id' => $planId,
        ]);
    }

    public function test_regular_user_cannot_manage_subscription_plans(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson('/api/auth/admin/subscriptions/plans');

        $response->assertStatus(403);
    }

    public function test_cannot_delete_plan_assigned_to_tenant(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Standard Plan',
            'slug' => 'standard-plan',
            'price' => 999.00,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/auth/admin/subscriptions/plans/{$plan->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }

    public function test_assigning_subscription_plan_prevents_duplicate_active_subscriptions(): void
    {
        $plan1 = SubscriptionPlan::create([
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
            'price' => 500,
            'billing_cycle' => 'monthly',
        ]);

        $plan2 = SubscriptionPlan::create([
            'name' => 'Growth Plan',
            'slug' => 'growth-plan',
            'price' => 1500,
            'billing_cycle' => 'monthly',
        ]);

        // Assign plan 1
        $res1 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/subscription", [
                'subscription_plan_id' => $plan1->id,
                'starts_at' => now()->toDateTimeString(),
            ]);
        $res1->assertStatus(201);
        $sub1Id = $res1->json('data.id');

        $this->assertEquals('active', TenantSubscription::find($sub1Id)->status);

        // Assign plan 2 (upgrade/change plan)
        $res2 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/subscription", [
                'subscription_plan_id' => $plan2->id,
                'starts_at' => now()->toDateTimeString(),
            ]);
        $res2->assertStatus(201);
        $sub2Id = $res2->json('data.id');

        // Plan 1 must be marked cancelled
        $this->assertEquals('cancelled', TenantSubscription::find($sub1Id)->status);
        $this->assertEquals('active', TenantSubscription::find($sub2Id)->status);

        // Exactly 1 active subscription exists for this tenant
        $activeCount = TenantSubscription::where('tenant_id', $this->tenant->id)
            ->where('status', 'active')
            ->count();
        $this->assertEquals(1, $activeCount);
    }

    public function test_tenant_isolation_for_viewing_subscription(): void
    {
        $tenant2 = Tenant::create([
            'company_name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
        ]);

        // Make regularUser a member of tenant 1 only
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->regularUser->id,
            'role' => 'agent',
            'status' => 'active',
        ]);

        $this->regularUser->assignRole('agent');

        // regularUser accessing tenant2 subscription should receive 403 Forbidden (since route requires super_admin or membership)
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$tenant2->id}/subscription");

        $response->assertStatus(403);
    }

    public function test_enforces_whatsapp_message_limit_and_period_tracking(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Limited Plan',
            'slug' => 'limited-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_whatsapp_messages' => 2,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $account = TenantWhatsAppAccount::create([
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => '10987654321',
            'access_token' => 'valid-mock-token-1234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $account->id,
            'customer_phone' => '919876543210',
            'status' => 'open',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.HBgLMTEwMA01']]], 200)
                ->push(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.HBgLMTEwMA02']]], 200),
        ]);

        $service = app(WhatsAppCloudApiService::class);

        // 1st message succeeds
        $service->send($conversation, $this->superAdmin, ['type' => 'text', 'body' => 'Hello 1']);

        // 2nd message succeeds
        $service->send($conversation, $this->superAdmin, ['type' => 'text', 'body' => 'Hello 2']);

        $usageRecord = app(SubscriptionUsageService::class)->getOrCreateCurrentUsageRecord($this->tenant->id);
        $this->assertEquals(2, $usageRecord->whatsapp_messages_count);

        // 3rd message should throw limit reached exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Subscription limit reached for WhatsApp messages');

        $service->send($conversation, $this->superAdmin, ['type' => 'text', 'body' => 'Hello 3']);
    }

    public function test_failed_whatsapp_message_does_not_increment_usage(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Message Plan',
            'slug' => 'message-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_whatsapp_messages' => 5,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $account = TenantWhatsAppAccount::create([
            'tenant_id' => $this->tenant->id,
            'phone_number_id' => '10987654321',
            'access_token' => 'valid-mock-token-1234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_account_id' => $account->id,
            'customer_phone' => '919876543210',
            'status' => 'open',
        ]);

        // Mock failed WhatsApp API response
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 400),
        ]);

        $service = app(WhatsAppCloudApiService::class);

        try {
            $service->send($conversation, $this->superAdmin, ['type' => 'text', 'body' => 'Test Failure']);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $usageRecord = app(SubscriptionUsageService::class)->getOrCreateCurrentUsageRecord($this->tenant->id);
        $this->assertEquals(0, $usageRecord->whatsapp_messages_count);
    }

    public function test_enforces_team_members_limit(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Team Limit Plan',
            'slug' => 'team-limit-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_team_members' => 1,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        // Add 1st member
        $res1 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/members", [
                'user_id' => $this->regularUser->id,
                'role' => 'agent',
            ]);
        $res1->assertStatus(201);

        $user2 = User::factory()->create();

        // 2nd member should exceed limit
        $res2 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/members", [
                'user_id' => $user2->id,
                'role' => 'agent',
            ]);
        $res2->assertStatus(422);
        $res2->assertJsonPath('message', 'Team members limit reached for this subscription plan (Maximum: 1).');
    }

    public function test_enforces_contacts_limit(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Contact Limit Plan',
            'slug' => 'contact-limit-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_contacts' => 1,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        // 1st contact succeeds
        $res1 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/contacts', [
                'tenant_id' => $this->tenant->id,
                'name' => 'John Doe',
                'phone' => '+919999999991',
            ]);
        $res1->assertStatus(201);

        // 2nd contact throws limit error (422)
        $res2 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/contacts', [
                'tenant_id' => $this->tenant->id,
                'name' => 'Jane Doe',
                'phone' => '+919999999992',
            ]);

        $res2->assertStatus(422);
        $res2->assertJsonPath('message', 'Contacts limit reached for this subscription plan (Maximum: 1).');
    }

    public function test_expired_subscription_blocks_action(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Expired Plan',
            'slug' => 'expired-plan',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'max_whatsapp_messages' => 100,
        ]);

        // Subscription ended yesterday
        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Your subscription plan has expired or is inactive. Please renew your plan.');

        app(SubscriptionUsageService::class)->assertCanUse($this->tenant->id, 'whatsapp_messages');
    }
    public function test_super_admin_can_list_subscription_features(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/subscriptions/plans/features');

        $response->assertOk()
            ->assertJsonFragment(['key' => 'max_users'])
            ->assertJsonFragment(['key' => 'max_contacts'])
            ->assertJsonFragment(['key' => 'automation_enabled'])
            ->assertJsonFragment(['key' => 'api_access_enabled']);
    }

    public function test_super_admin_can_update_plan_entitlements(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Entitlement Test Plan',
            'code' => 'ENTITLEMENT-TEST',
            'slug' => 'entitlement-test-plan',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson(
                "/api/auth/admin/subscriptions/plans/{$plan->id}/entitlements",
                [
                    'entitlements' => [
                        'max_users' => 10,
                        'max_contacts' => 500,
                        'max_whatsapp_numbers' => 3,
                        'max_monthly_campaigns' => 20,
                        'max_monthly_messages' => 5000,
                        'automation_enabled' => true,
                        'api_access_enabled' => false,
                    ],
                ]
            );

        $response->assertOk()
            ->assertJsonPath('data.entitlements.max_users', 10)
            ->assertJsonPath('data.entitlements.automation_enabled', true);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'max_team_members' => 10,
            'max_contacts' => 500,
            'max_whatsapp_accounts' => 3,
            'max_whatsapp_messages' => 5000,
        ]);

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'max_monthly_campaigns',
        ]);

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'automation_enabled',
        ]);
    }

    public function test_invalid_plan_entitlements_are_rejected(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Invalid Entitlement Test',
            'code' => 'INVALID-ENTITLEMENT',
            'slug' => 'invalid-entitlement-test',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson(
                "/api/auth/admin/subscriptions/plans/{$plan->id}/entitlements",
                [
                    'entitlements' => [
                        'max_users' => -1,
                        'automation_enabled' => 'not-a-boolean',
                    ],
                ]
            );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'max_users',
        ]);
    }

    public function test_regular_user_cannot_update_plan_entitlements(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Restricted Entitlement Test',
            'code' => 'RESTRICTED-ENTITLEMENT',
            'slug' => 'restricted-entitlement-test',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson(
                "/api/auth/admin/subscriptions/plans/{$plan->id}/entitlements",
                [
                    'entitlements' => [
                        'max_users' => 50,
                    ],
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('plan_features', [
            'plan_id' => $plan->id,
            'feature_key' => 'max_users',
        ]);
    }

    public function test_super_admin_can_preview_plan_archive_impact(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Preview Archive Plan',
            'code' => 'PREVIEW-ARCHIVE',
            'slug' => 'preview-archive-plan',
            'price' => 99,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/subscriptions/plans/{$plan->id}/archive-preview");

        $response->assertOk()
            ->assertJsonPath('data.plan_id', $plan->id)
            ->assertJsonPath('data.current_status', 'published')
            ->assertJsonPath('data.already_archived', false)
            ->assertJsonPath('data.subscriptions.total', 2)
            ->assertJsonPath('data.subscriptions.active', 1)
            ->assertJsonPath('data.subscriptions.trial', 1)
            ->assertJsonPath('data.subscriptions.past_due', 0)
            ->assertJsonPath('data.impact.confirmation_required', true)
            ->assertJsonPath('data.impact.existing_subscriptions_preserved', true);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'status' => 'published',
            'is_active' => true,
        ]);
    }

    public function test_regular_user_cannot_preview_plan_archive_impact(): void
    {
        $user = User::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Restricted Preview Plan',
            'code' => 'RESTRICTED-PREVIEW',
            'slug' => 'restricted-preview-plan',
            'price' => 99,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/auth/admin/subscriptions/plans/{$plan->id}/archive-preview")
            ->assertForbidden();

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'status' => 'published',
            'is_active' => true,
        ]);
    }


    public function test_archiving_plan_disables_new_signups_and_preserves_existing_subscription(): void
    {
        $admin = $this->superAdmin;

        $plan = SubscriptionPlan::create([
            'name' => 'Archive Test Plan',
            'code' => 'ARCHIVE-TEST',
            'slug' => 'archive-test-plan',
            'price' => 99,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);

        $subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $archiveUrl = "/api/auth/admin/subscriptions/plans/{$plan->id}/archive";

        $this->actingAs($admin, 'sanctum')
            ->postJson($archiveUrl)
            ->assertStatus(409)
            ->assertJsonPath('confirmation_required', true)
            ->assertJsonPath('subscribers_count', 1);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'status' => 'published',
            'is_active' => true,
            'available_for_signup' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($archiveUrl, ['confirm' => true]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.available_for_signup', false)
            ->assertJsonPath('existing_subscriptions_preserved', true);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'status' => 'archived',
            'is_active' => false,
            'available_for_signup' => false,
        ]);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }


    public function test_archiving_an_already_archived_plan_returns_unprocessable_entity(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $plan = SubscriptionPlan::create([
            'name' => 'Already Archived Plan',
            'code' => 'ALREADY-ARCHIVED',
            'slug' => 'already-archived-plan',
            'price' => 99,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => false,
            'status' => 'archived',
            'available_for_signup' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/auth/admin/subscriptions/plans/{$plan->id}/archive")
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'Subscription plan is already archived.',
            ]);
    }

    public function test_regular_user_cannot_archive_subscription_plan(): void
    {
        $user = User::factory()->create();

        $plan = SubscriptionPlan::create([
            'name' => 'Restricted Archive Plan',
            'code' => 'RESTRICTED-ARCHIVE',
            'slug' => 'restricted-archive-plan',
            'price' => 99,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/auth/admin/subscriptions/plans/{$plan->id}/archive")
            ->assertForbidden();

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'status' => 'published',
            'is_active' => true,
        ]);
    }
}
