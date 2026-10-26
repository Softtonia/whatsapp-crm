<?php

namespace Tests\Feature;

use App\Exceptions\QuotaExceededException;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUsageLedger;
use App\Models\TenantUsageRecord;
use App\Models\TenantUser;
use App\Models\TenantWhatsAppAccount;
use App\Models\User;
use App\Services\QuotaEnforcementService;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UsageMonitoringAndQuotasTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularAdmin;
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected SubscriptionPlan $basicPlan;
    protected SubscriptionPlan $proPlan;
    protected QuotaEnforcementService $quotaService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles and Permissions
        $superAdminRole = Role::findOrCreate('Super Admin', 'web');
        $superAdminRole->slug = 'super_admin';
        $superAdminRole->save();

        $adminRole = Role::findOrCreate('Tenant Admin', 'web');
        $adminRole->slug = 'tenant_admin';
        $adminRole->save();

        $usageReadPerm = Permission::findOrCreate('usage.read', 'web');
        $superAdminRole->givePermissionTo($usageReadPerm);

        // 2. Users
        $this->superAdmin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'status' => 'active',
        ]);
        $this->superAdmin->assignRole($superAdminRole);

        $this->regularAdmin = User::factory()->create([
            'email' => 'regular@example.com',
            'status' => 'active',
        ]);
        $this->regularAdmin->assignRole($adminRole);
        $this->regularAdmin->givePermissionTo($usageReadPerm);

        // 3. Tenants
        $this->tenantA = Tenant::create([
            'company_name' => 'Acme Corp A',
            'slug' => 'acme-corp-a',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'company_name' => 'Beta Corp B',
            'slug' => 'beta-corp-b',
            'status' => 'active',
        ]);

        // Connect regularAdmin to Tenant A only
        TenantUser::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->regularAdmin->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        // 4. Plans
        $this->basicPlan = SubscriptionPlan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
            'billing_cycle' => 'monthly',
            'max_whatsapp_accounts' => 2, // max_whatsapp_numbers
            'max_whatsapp_messages' => 100,
            'max_ai_replies' => 50,
            'max_team_members' => 5,
            'max_contacts' => 500,
        ]);

        // Plan Feature for monthly campaigns
        PlanFeature::create([
            'plan_id' => $this->basicPlan->id,
            'feature_key' => 'max_monthly_campaigns',
            'feature_name' => 'Maximum Monthly Campaigns',
            'feature_type' => 'integer',
            'value' => 3,
        ]);

        $this->proPlan = SubscriptionPlan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
            'billing_cycle' => 'monthly',
            'max_whatsapp_accounts' => 10,
            'max_whatsapp_messages' => 1000,
            'max_ai_replies' => 500,
            'max_team_members' => 20,
            'max_contacts' => 5000,
        ]);

        PlanFeature::create([
            'plan_id' => $this->proPlan->id,
            'feature_key' => 'max_monthly_campaigns',
            'feature_name' => 'Maximum Monthly Campaigns',
            'feature_type' => 'integer',
            'value' => 20,
        ]);

        // Assign basic plan to tenant A
        TenantSubscription::create([
            'tenant_id' => $this->tenantA->id,
            'subscription_plan_id' => $this->basicPlan->id,
            'status' => 'active',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
        ]);

        // Assign pro plan to tenant B
        TenantSubscription::create([
            'tenant_id' => $this->tenantB->id,
            'subscription_plan_id' => $this->proPlan->id,
            'status' => 'active',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
        ]);

        $this->quotaService = app(QuotaEnforcementService::class);
    }

    /**
     * USAGE-001 — Quota below limit: Operation succeeds and usage increases exactly once.
     */
    public function test_usage_001_quota_below_limit_succeeds_and_increments_once(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        $this->assertEquals(0, $this->quotaService->getCurrentUsage($tenantId, $metric));
        $this->assertEquals(3, $this->quotaService->getEffectiveLimit($tenantId, $metric));

        // Reserve 1 campaign
        $ledger = $this->quotaService->reserveQuota(
            tenantId: $tenantId,
            metric: $metric,
            quantity: 1,
            idempotencyKey: 'camp_init_001',
            sourceType: 'campaign',
            sourceId: '101'
        );

        $this->assertNotNull($ledger);
        $this->assertEquals(1, $this->quotaService->getCurrentUsage($tenantId, $metric));
        $this->assertDatabaseHas('tenant_usage_ledgers', [
            'tenant_id' => $tenantId,
            'metric_key' => $metric,
            'idempotency_key' => 'camp_init_001',
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('tenant_usage_records', [
            'tenant_id' => $tenantId,
            'campaigns_count' => 1,
        ]);
    }

    /**
     * USAGE-002 — Quota reached: Billable operation rejected with structured quota error; usage does not increase.
     */
    public function test_usage_002_quota_reached_rejects_and_does_not_increase_usage(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        // Consume up to limit (3)
        $this->quotaService->reserveQuota($tenantId, $metric, 1, 'c1');
        $this->quotaService->reserveQuota($tenantId, $metric, 1, 'c2');
        $this->quotaService->reserveQuota($tenantId, $metric, 1, 'c3');

        $this->assertEquals(3, $this->quotaService->getCurrentUsage($tenantId, $metric));

        // 4th attempt must throw QuotaExceededException
        $this->expectException(QuotaExceededException::class);

        try {
            $this->quotaService->reserveQuota($tenantId, $metric, 1, 'c4');
        } finally {
            // Assert usage remained at 3
            $this->assertEquals(3, $this->quotaService->getCurrentUsage($tenantId, $metric));
        }
    }

    /**
     * USAGE-003 — Concurrent usage increments: Assert that no more operations succeed than remaining quota allows.
     */
    public function test_usage_003_concurrent_usage_increments_enforce_limit(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        // Effective limit is 3. We simulate 5 rapid reservation requests.
        $successCount = 0;
        $failCount = 0;

        for ($i = 1; $i <= 5; $i++) {
            try {
                $this->quotaService->reserveQuota($tenantId, $metric, 1, "concurrent_req_{$i}");
                $successCount++;
            } catch (QuotaExceededException $e) {
                $failCount++;
            }
        }

        $this->assertEquals(3, $successCount);
        $this->assertEquals(2, $failCount);
        $this->assertEquals(3, $this->quotaService->getCurrentUsage($tenantId, $metric));
    }

    /**
     * USAGE-004 — Duplicate event: Repeated source event with same idempotency key does not increment usage twice.
     */
    public function test_usage_004_duplicate_event_does_not_double_count(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_WHATSAPP_MESSAGES;
        $idempotencyKey = 'msg_event_webhook_unique_9988';

        // First delivery
        $ledger1 = $this->quotaService->reserveQuota(
            tenantId: $tenantId,
            metric: $metric,
            quantity: 5,
            idempotencyKey: $idempotencyKey,
            sourceType: 'webhook_message'
        );

        $this->assertEquals(5, $this->quotaService->getCurrentUsage($tenantId, $metric));

        // Duplicate delivery (webhook retry or job repeat)
        $ledger2 = $this->quotaService->reserveQuota(
            tenantId: $tenantId,
            metric: $metric,
            quantity: 5,
            idempotencyKey: $idempotencyKey,
            sourceType: 'webhook_message'
        );

        $this->assertEquals($ledger1->id, $ledger2->id);
        $this->assertEquals(5, $this->quotaService->getCurrentUsage($tenantId, $metric));
        $this->assertEquals(1, TenantUsageLedger::where('idempotency_key', $idempotencyKey)->count());
    }

    /**
     * USAGE-005 — Period rollover: Usage in previous period remains intact; new period starts with 0.
     */
    public function test_usage_005_period_rollover_preserves_history_and_resets(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        // Current month usage
        $this->quotaService->reserveQuota($tenantId, $metric, 2, 'period_curr_1');
        $this->assertEquals(2, $this->quotaService->getCurrentUsage($tenantId, $metric));

        // Create an explicit historical record for last month
        $pastStart = now()->subMonth()->startOfMonth()->toDateString();
        $pastEnd = now()->subMonth()->endOfMonth()->toDateString();

        TenantUsageRecord::create([
            'tenant_id' => $tenantId,
            'period_start' => $pastStart,
            'period_end' => $pastEnd,
            'whatsapp_messages_count' => 80,
            'ai_replies_count' => 30,
            'campaigns_count' => 3,
        ]);

        // Historical query returns previous period intact
        $history = $this->quotaService->getTenantUsageHistory($tenantId);
        $this->assertGreaterThanOrEqual(2, $history->total());

        $pastRecord = TenantUsageRecord::where('tenant_id', $tenantId)
            ->whereDate('period_start', $pastStart)
            ->first();

        $this->assertNotNull($pastRecord);
        $this->assertEquals(3, $pastRecord->campaigns_count);
        $this->assertEquals(80, $pastRecord->whatsapp_messages_count);
    }

    /**
     * USAGE-006 — Plan change: Effective limits update, historical usage remains available, over-limit status updates.
     */
    public function test_usage_006_plan_change_updates_limits_and_over_limit_behavior(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        // Consume 3 campaigns on Basic Plan (limit 3)
        $this->quotaService->reserveQuota($tenantId, $metric, 3, 'pc_1');

        $quotas = $this->quotaService->getTenantQuotas($tenantId);
        $this->assertEquals(3, $quotas['quotas'][$metric]['current_usage']);
        $this->assertEquals(3, $quotas['quotas'][$metric]['effective_limit']);
        $this->assertEquals(0, $quotas['quotas'][$metric]['remaining']);
        $this->assertFalse($quotas['quotas'][$metric]['is_over_limit']);

        // Upgrade tenant to Pro Plan (limit 20)
        TenantSubscription::where('tenant_id', $tenantId)->update([
            'subscription_plan_id' => $this->proPlan->id,
        ]);

        $upgradedQuotas = $this->quotaService->getTenantQuotas($tenantId);
        $this->assertEquals(3, $upgradedQuotas['quotas'][$metric]['current_usage']);
        $this->assertEquals(20, $upgradedQuotas['quotas'][$metric]['effective_limit']);
        $this->assertEquals(17, $upgradedQuotas['quotas'][$metric]['remaining']);

        // Now downgrade to a very low limit plan (limit 2)
        $miniPlan = SubscriptionPlan::create([
            'name' => 'Mini Plan',
            'slug' => 'mini-plan',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
            'billing_cycle' => 'monthly',
        ]);
        PlanFeature::create([
            'plan_id' => $miniPlan->id,
            'feature_key' => 'max_monthly_campaigns',
            'feature_name' => 'Maximum Monthly Campaigns',
            'feature_type' => 'integer',
            'value' => 2,
        ]);

        TenantSubscription::where('tenant_id', $tenantId)->update([
            'subscription_plan_id' => $miniPlan->id,
        ]);

        $downgradedQuotas = $this->quotaService->getTenantQuotas($tenantId);
        $this->assertEquals(3, $downgradedQuotas['quotas'][$metric]['current_usage']);
        $this->assertEquals(2, $downgradedQuotas['quotas'][$metric]['effective_limit']);
        $this->assertTrue($downgradedQuotas['quotas'][$metric]['is_over_limit']);
        $this->assertEquals('blocked', $downgradedQuotas['quotas'][$metric]['enforcement_status']);
    }

    /**
     * Test GET /api/auth/admin/usage/summary
     */
    public function test_api_usage_summary(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/usage/summary');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_tenants_included',
                    'tenants_at_or_over_limit',
                    'over_limit_tenants',
                    'metric_aggregates',
                    'period',
                    'metric_definitions',
                ],
            ]);
    }

    /**
     * Test GET /api/auth/admin/usage/tenants with filters and pagination
     */
    public function test_api_usage_tenants_with_filters(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/usage/tenants?per_page=10&page=1&search=Acme');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'tenant_id',
                        'name',
                        'status',
                        'subscription',
                        'period',
                        'is_over_limit',
                        'enforcement_status',
                        'quotas',
                    ],
                ],
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    }

    /**
     * Test GET /api/auth/admin/tenants/{id}/usage
     */
    public function test_api_tenant_usage_history(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$this->tenantA->id}/usage");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'meta',
            ]);
    }

    /**
     * Test GET /api/auth/admin/tenants/{id}/quotas
     */
    public function test_api_tenant_quotas(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$this->tenantA->id}/quotas");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'tenant',
                    'subscription',
                    'period',
                    'is_over_limit',
                    'quotas',
                ],
            ]);
    }

    /**
     * Test cross-tenant isolation: Tenant-scoped admin cannot view another tenant's quotas or usage.
     */
    public function test_cross_tenant_access_denied(): void
    {
        // regularAdmin belongs to tenant A only. Trying to view tenant B must return 403 Forbidden.
        $response = $this->actingAs($this->regularAdmin, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$this->tenantB->id}/quotas");

        $response->assertStatus(403);

        $usageResponse = $this->actingAs($this->regularAdmin, 'sanctum')
            ->getJson("/api/auth/admin/tenants/{$this->tenantB->id}/usage");

        $usageResponse->assertStatus(403);
    }

    /**
     * Test permission denial: User without usage.read receives 403.
     */
    public function test_permission_denial_without_usage_read(): void
    {
        $noPermUser = User::factory()->create(['status' => 'active']);
        $role = Role::findOrCreate('Viewer', 'web');
        $noPermUser->assignRole($role);

        $response = $this->actingAs($noPermUser, 'sanctum')
            ->getJson('/api/auth/admin/usage/summary');

        $response->assertStatus(403);
    }

    /**
     * Test rollback on failed business operation: Failed campaign creation releases reserved quota.
     */
    public function test_failed_business_operation_releases_quota(): void
    {
        $tenantId = $this->tenantA->id;
        $metric = QuotaEnforcementService::METRIC_MONTHLY_CAMPAIGNS;

        $ledger = $this->quotaService->reserveQuota(
            tenantId: $tenantId,
            metric: $metric,
            quantity: 1,
            idempotencyKey: 'fail_test_key'
        );

        $this->assertEquals(1, $this->quotaService->getCurrentUsage($tenantId, $metric));

        // Simulate failure and release
        $this->quotaService->releaseReservation($ledger);

        $this->assertEquals(0, $this->quotaService->getCurrentUsage($tenantId, $metric));
        $this->assertDatabaseMissing('tenant_usage_ledgers', ['idempotency_key' => 'fail_test_key']);
    }

    /**
     * Test WhatsApp numbers resource limit enforcement via controller.
     */
    public function test_whatsapp_numbers_quota_enforcement(): void
    {
        // Tenant A limit is 2 WhatsApp accounts
        $this->assertEquals(2, $this->quotaService->getEffectiveLimit($this->tenantA->id, QuotaEnforcementService::METRIC_WHATSAPP_NUMBERS));

        // Account 1 succeeds
        $res1 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/inbox/accounts', [
                'tenant_id' => $this->tenantA->id,
                'phone_number_id' => 'phone_num_1',
                'access_token' => 'token_12345678901234567890',
            ]);
        $res1->assertStatus(201);

        // Account 2 succeeds
        $res2 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/inbox/accounts', [
                'tenant_id' => $this->tenantA->id,
                'phone_number_id' => 'phone_num_2',
                'access_token' => 'token_12345678901234567890',
            ]);
        $res2->assertStatus(201);

        // Account 3 exceeds limit of 2 -> 422 Quota exceeded
        $res3 = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/auth/admin/inbox/accounts', [
                'tenant_id' => $this->tenantA->id,
                'phone_number_id' => 'phone_num_3',
                'access_token' => 'token_12345678901234567890',
            ]);
        $res3->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Quota exceeded.');
    }
}
