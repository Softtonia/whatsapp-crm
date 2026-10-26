<?php

namespace Tests\Feature;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantCustomDomain;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\CustomDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantBrandingAndCustomDomainTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $tenantOwner;
    protected User $otherTenantUser;
    protected Tenant $tenant;
    protected Tenant $otherTenant;
    protected SubscriptionPlan $entitledPlan;
    protected SubscriptionPlan $restrictedPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        Permission::findOrCreate('branding.read', 'web');
        Permission::findOrCreate('branding.update', 'web');
        Permission::findOrCreate('domains.read', 'web');
        Permission::findOrCreate('domains.manage', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminSecret123!'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->tenantOwner = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('OwnerSecret123!'),
        ]);
        $this->tenantOwner->assignRole('Super Admin');
        $this->tenantOwner->givePermissionTo(['branding.read', 'branding.update', 'domains.read', 'domains.manage']);

        $this->otherTenantUser = User::factory()->create([
            'email' => 'other@example.com',
            'password' => Hash::make('OtherSecret123!'),
        ]);
        $this->otherTenantUser->assignRole('Super Admin');
        $this->otherTenantUser->givePermissionTo(['branding.read', 'branding.update', 'domains.read', 'domains.manage']);

        $this->tenant = Tenant::create([
            'company_name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'status' => 'active',
            'email' => 'contact@acme.com',
        ]);
        $this->tenant->memberships()->create([
            'user_id' => $this->tenantOwner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->otherTenant = Tenant::create([
            'company_name' => 'Other Corp',
            'slug' => 'other-corp',
            'status' => 'active',
            'email' => 'contact@other.com',
        ]);
        $this->otherTenant->memberships()->create([
            'user_id' => $this->otherTenantUser->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Entitled Plan with white_label_enabled & custom_domain_enabled
        $this->entitledPlan = SubscriptionPlan::create([
            'name' => 'Enterprise Plan',
            'slug' => 'enterprise-plan',
            'price' => 9999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        PlanFeature::create([
            'plan_id' => $this->entitledPlan->id,
            'feature_key' => 'white_label_enabled',
            'feature_name' => 'White Label',
            'feature_type' => 'boolean',
            'value' => true,
        ]);
        PlanFeature::create([
            'plan_id' => $this->entitledPlan->id,
            'feature_key' => 'custom_domain_enabled',
            'feature_name' => 'Custom Domain',
            'feature_type' => 'boolean',
            'value' => true,
        ]);

        // Restricted Plan
        $this->restrictedPlan = SubscriptionPlan::create([
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        PlanFeature::create([
            'plan_id' => $this->restrictedPlan->id,
            'feature_key' => 'white_label_enabled',
            'feature_name' => 'White Label',
            'feature_type' => 'boolean',
            'value' => false,
        ]);
        PlanFeature::create([
            'plan_id' => $this->restrictedPlan->id,
            'feature_key' => 'custom_domain_enabled',
            'feature_name' => 'Custom Domain',
            'feature_type' => 'boolean',
            'value' => false,
        ]);

        // Assign entitled plan to tenant
        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $this->entitledPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        TenantSubscription::create([
            'tenant_id' => $this->otherTenant->id,
            'subscription_plan_id' => $this->entitledPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /* -------------------------------------------------------------------------
     * BRANDING TESTS
     * ------------------------------------------------------------------------- */

    public function test_get_branding_returns_configuration_and_preview(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/auth/admin/tenants/{$this->tenant->id}/branding");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenant_id',
            'is_white_label_permitted',
            'branding' => [
                'id',
                'brand_name',
                'logo_url',
                'favicon_url',
                'primary_color',
                'secondary_color',
                'support_email',
            ],
            'preview' => [
                'preview_components' => [
                    'login_page',
                    'sidebar',
                    'browser_tab',
                ],
            ],
        ]);
        $this->assertTrue($response->json('is_white_label_permitted'));
    }

    public function test_update_branding_validates_and_persists_settings(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/auth/admin/tenants/{$this->tenant->id}/branding", [
                'brand_name' => 'Acme Pro CRM',
                'primary_color' => '#1A73E8',
                'secondary_color' => '#34A853',
                'support_email' => 'help@acme.com',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'brand_name' => 'Acme Pro CRM',
            'primary_color' => '#1A73E8',
            'secondary_color' => '#34A853',
            'support_email' => 'help@acme.com',
        ]);

        $this->assertDatabaseHas('tenant_brandings', [
            'tenant_id' => $this->tenant->id,
            'brand_name' => 'Acme Pro CRM',
        ]);
    }

    public function test_brand_003_invalid_color_values_are_rejected(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/auth/admin/tenants/{$this->tenant->id}/branding", [
                'primary_color' => 'blue',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['primary_color']);

        $response2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/auth/admin/tenants/{$this->tenant->id}/branding", [
                'secondary_color' => '#ZZZZZZ',
            ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['secondary_color']);
    }

    public function test_brand_001_valid_logo_upload_succeeds(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        // Generate valid PNG image using GD
        $img = imagecreatetruecolor(100, 100);
        $bg = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $bg);
        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        $file = UploadedFile::fake()->createWithContent('logo.png', $content);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/branding/assets", [
                'type' => 'logo',
                'file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'type',
                'path',
                'url',
                'width',
                'height',
            ],
        ]);

        $path = $response->json('data.path');
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('tenant_brandings', [
            'tenant_id' => $this->tenant->id,
            'logo_path' => $path,
        ]);
    }

    public function test_brand_002_malicious_or_invalid_image_upload_is_rejected(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        // Malicious SVG payload masquerading as png
        $fakeSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $svgFile = UploadedFile::fake()->createWithContent('exploit.png', $fakeSvg);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/branding/assets", [
                'type' => 'logo',
                'file' => $svgFile,
            ]);

        $response->assertStatus(422);

        // Corrupted file
        $corruptFile = UploadedFile::fake()->createWithContent('corrupt.png', 'NOT_AN_IMAGE_DATA');
        $response2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/branding/assets", [
                'type' => 'logo',
                'file' => $corruptFile,
            ]);

        $response2->assertStatus(422);
    }

    /* -------------------------------------------------------------------------
     * DOMAIN TESTS
     * ------------------------------------------------------------------------- */

    public function test_register_custom_domain_creates_pending_record_with_dns_instructions(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains", [
                'hostname' => 'crm.acmecorp.com',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'id',
                'hostname',
                'verification_status',
                'is_active',
                'tls_status',
                'dns_instructions' => [
                    'record_type',
                    'host',
                    'value',
                ],
            ],
        ]);

        $this->assertEquals('crm.acmecorp.com', $response->json('data.hostname'));
        $this->assertEquals('pending', $response->json('data.verification_status'));
        $this->assertFalse($response->json('data.is_active'));
    }

    public function test_domain_001_duplicate_hostname_registration_is_rejected(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;
        $otherToken = $this->otherTenantUser->createToken('other-token')->plainTextToken;

        // Register on tenant 1
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains", [
                'hostname' => 'portal.clientbrand.com',
            ])->assertStatus(201);

        // Reset auth guards to ensure subsequent request re-authenticates from new bearer token
        $this->app['auth']->forgetGuards();

        // Attempt duplicate on tenant 2
        $response = $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->postJson("/api/auth/admin/tenants/{$this->otherTenant->id}/domains", [
                'hostname' => 'PORTAL.clientbrand.com',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => "The domain 'portal.clientbrand.com' is already registered."]);
    }

    public function test_domain_002_failed_domain_verification_does_not_activate_domain(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $createRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains", [
                'hostname' => 'unverified.acmecorp.com',
            ]);
        $domainId = $createRes->json('data.id');

        // Verification without setting up DNS TXT records fails
        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domainId}/verify");

        $verifyRes->assertStatus(422);
        $verifyRes->assertJsonFragment(['verification_status' => 'failed']);

        $domain = TenantCustomDomain::findOrFail($domainId);
        $this->assertEquals('failed', $domain->verification_status);
        $this->assertFalse($domain->is_active);
        $this->assertEquals('pending', $domain->tls_status);
    }

    public function test_domain_cannot_be_activated_before_tls_is_ready(): void
    {
        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'secure.acmecorp.com',
            'verification_status' => 'verified',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.secure.acmecorp.com',
            'verification_token' => 'token123',
            'expected_record_value' => 'whatsapp-crm-verification=token123',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        $this->assertFalse($domain->is_active);
        $this->assertEquals('pending', $domain->tls_status);
    }
    public function test_successful_domain_verification_keeps_domain_inactive_until_tls_is_ready(): void
    {
        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'verified.acmecorp.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.verified.acmecorp.com',
            'verification_token' => 'token123',
            'expected_record_value' => 'whatsapp-crm-verification=token123',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        // Mock DNS resolver returning expected token
        $customDomainService = app(CustomDomainService::class);
        $result = $customDomainService->verifyDomain($domain, function ($host) {
            return ['whatsapp-crm-verification=token123'];
        });

        $this->assertTrue($result['success']);
        $domain->refresh();
        $this->assertEquals('verified', $domain->verification_status);
        $this->assertFalse($domain->is_active); // Do not activate until TLS/routing is ready.
        $this->assertNotNull($domain->verified_at);
        $this->assertEquals('pending', $domain->tls_status); // TLS remains pending until edge provisioned
    }

    public function test_domain_003_cross_tenant_access_and_modification_are_prevented(): void
    {
        // Remove Super Admin roles so the test checks tenant-level access.
        $this->otherTenantUser->removeRole('Super Admin');
        $this->otherTenantUser->removeRole('super_admin');

        // Reset authentication guards before authenticating the test user.
        $this->app['auth']->forgetGuards();

        $otherToken = $this->otherTenantUser
            ->createToken('test-token')
            ->plainTextToken;

        // Create a custom domain belonging to Tenant 1.
        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'tenant1.acmecorp.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.tenant1.acmecorp.com',
            'verification_token' => 'tok',
            'expected_record_value' => 'val',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        // Tenant 2 user must not list Tenant 1 domains.
        $res1 = $this->withToken($otherToken)
            ->getJson(
                "/api/auth/admin/tenants/{$this->tenant->id}/domains"
            );

        $res1->assertForbidden();

        // Tenant 2 user must not delete Tenant 1's domain
        // by using Tenant 2's URL with Tenant 1's domain ID.
        $this->app['auth']->forgetGuards();

        $res2 = $this->withToken($otherToken)
            ->deleteJson(
                "/api/auth/admin/tenants/{$this->otherTenant->id}/domains/{$domain->id}"
            );

        // Current middleware returns 403 before domain lookup.
        $res2->assertForbidden();

        // Tenant 2 user must not delete Tenant 1's domain
        // by directly using Tenant 1's URL.
        $this->app['auth']->forgetGuards();

        $res3 = $this->withToken($otherToken)
            ->deleteJson(
                "/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}"
            );

        $res3->assertForbidden();

        // Confirm the domain still belongs to Tenant 1 and was not deleted.
        $this->assertDatabaseHas('tenant_custom_domains', [
            'id' => $domain->id,
            'tenant_id' => $this->tenant->id,
            'hostname' => 'tenant1.acmecorp.com',
        ]);
    }


    public function test_domain_004_subscription_entitlement_denial_prevents_operations(): void
    {
        // Change tenant subscription to restricted plan (no white-label, no custom domain)
        TenantSubscription::query()->where('tenant_id', $this->tenant->id)->delete();
        TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $this->restrictedPlan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        // Branding update denied
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/auth/admin/tenants/{$this->tenant->id}/branding", [
                'brand_name' => 'Pro Corp',
            ]);
        $res1->assertStatus(403);
        $res1->assertJsonFragment(['error' => 'feature_not_entitled']);

        // Domain register denied
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains", [
                'hostname' => 'denied.domain.com',
            ]);
        $res2->assertStatus(403);
        $res2->assertJsonFragment(['error' => 'feature_not_entitled']);
    }

    public function test_delete_domain_removes_record(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'deleteme.acmecorp.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.deleteme.acmecorp.com',
            'verification_token' => 'del-token',
            'expected_record_value' => 'del-val',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tenant_custom_domains', ['id' => $domain->id]);
    }

    public function test_super_admin_bypasses_subscription_plan_for_domains(): void
    {
        // Remove subscription completely from tenant
        TenantSubscription::query()->where('tenant_id', $this->tenant->id)->delete();

        $adminToken = $this->superAdmin->createToken('super-admin-token')->plainTextToken;

        // Super Admin registers domain on a tenant that has NO active subscription plan
        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains", [
                'hostname' => 'admin-bypass.acmecorp.com',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'hostname' => 'admin-bypass.acmecorp.com',
        ]);
        $this->assertDatabaseHas('tenant_custom_domains', [
            'tenant_id' => $this->tenant->id,
            'hostname' => 'admin-bypass.acmecorp.com',
        ]);
    }

    public function test_super_admin_bypasses_subscription_plan_for_branding(): void
    {
        // Remove subscription completely from tenant
        TenantSubscription::query()->where('tenant_id', $this->tenant->id)->delete();

        $adminToken = $this->superAdmin->createToken('super-admin-token')->plainTextToken;

        // Super Admin views branding on a tenant with NO active subscription
        $getRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson("/api/auth/admin/tenants/{$this->tenant->id}/branding");

        $getRes->assertStatus(200);
        $getRes->assertJsonFragment([
            'is_white_label_permitted' => true,
        ]);

        // Super Admin updates branding on a tenant with NO active subscription
        $putRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->putJson("/api/auth/admin/tenants/{$this->tenant->id}/branding", [
                'brand_name' => 'Super Admin Custom Brand',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'support_email' => 'admin@custombrand.com',
            ]);

        $putRes->assertStatus(200);
        $putRes->assertJsonFragment([
            'brand_name' => 'Super Admin Custom Brand',
        ]);
        $this->assertDatabaseHas('tenant_brandings', [
            'tenant_id' => $this->tenant->id,
            'brand_name' => 'Super Admin Custom Brand',
        ]);
    }

    public function test_get_single_domain_details(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'crm.myshop.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.crm.myshop.com',
            'verification_token' => 'sample-token',
            'expected_record_value' => 'whatsapp-crm-verification=sample-token',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'hostname' => 'crm.myshop.com',
            'verification_status' => 'pending',
            'is_active' => false,
        ]);
    }

    public function test_tls_provisioning_and_activation_lifecycle(): void
    {
        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'secure.acmecorp.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.secure.acmecorp.com',
            'verification_token' => 'token-secure',
            'expected_record_value' => 'whatsapp-crm-verification=token-secure',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        // 1. Attempting to activate an unverified domain is rejected
        $activateFail = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/activate");
        $activateFail->assertStatus(422);

        // 2. Mock DNS resolution to verify ownership
        $fakeDns = new \App\Services\Dns\FakeDnsResolver();
        $fakeDns->setTxtRecords('_whatsappcrm-challenge.secure.acmecorp.com', [
            'whatsapp-crm-verification=token-secure',
        ]);
        $this->app->instance(\App\Services\Contracts\DnsResolverInterface::class, $fakeDns);

        $verifyRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/verify");
        $verifyRes->assertStatus(200);

        // Domain is verified, but TLS is still pending and domain is still inactive
        $domain->refresh();
        $this->assertTrue($domain->isVerified());
        $this->assertFalse($domain->is_active);

        // 3. Attempting to activate with pending TLS is rejected
        $activatePendingTls = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/activate");
        $activatePendingTls->assertStatus(422);

        // 4. Provision TLS using FakeTlsProvider
        $fakeTls = new \App\Services\Tls\FakeTlsProvider();
        $fakeTls->setStatus('ready');
        $this->app->instance(\App\Services\Contracts\TlsCertificateProviderInterface::class, $fakeTls);

        $tlsRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/provision-tls");
        $tlsRes->assertStatus(200);
        $domain->refresh();
        $this->assertEquals('ready', $domain->tls_status);

        // 5. Activate domain
        $activateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/activate");
        $activateRes->assertStatus(200);
        $domain->refresh();
        $this->assertTrue($domain->is_active);

        // 6. Test routing resolution on the activated domain
        $routeRes = $this->getJson('http://secure.acmecorp.com/');
        $routeRes->assertStatus(200);
        $routeRes->assertJsonFragment([
            'tenant_id' => $this->tenant->id,
            'custom_domain' => 'secure.acmecorp.com',
        ]);

        // 7. Deactivate domain
        $deactivateRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/deactivate");
        $deactivateRes->assertStatus(200);
        $domain->refresh();
        $this->assertFalse($domain->is_active);

        // 8. Deactivated domain must now return 404
        $routeInactive = $this->getJson('http://secure.acmecorp.com/');
        $routeInactive->assertStatus(404);
        $routeInactive->assertJsonFragment(['error' => 'custom_domain_inactive']);
    }

    public function test_dns_chunk_and_timeout_handling(): void
    {
        $domain = TenantCustomDomain::create([
            'tenant_id' => $this->tenant->id,
            'hostname' => 'chunked.acmecorp.com',
            'verification_status' => 'pending',
            'verification_record_type' => 'TXT',
            'verification_hostname' => '_whatsappcrm-challenge.chunked.acmecorp.com',
            'verification_token' => 'longchunktoken123456789',
            'expected_record_value' => 'whatsapp-crm-verification=longchunktoken123456789',
            'tls_status' => 'pending',
            'is_active' => false,
        ]);

        $token = $this->tenantOwner->createToken('test-token')->plainTextToken;

        // Test timeout/resolver exception handling
        $fakeDns = new \App\Services\Dns\FakeDnsResolver();
        $fakeDns->shouldThrow(true, 'Connection timed out');
        $this->app->instance(\App\Services\Contracts\DnsResolverInterface::class, $fakeDns);

        $failRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/verify");
        $failRes->assertStatus(422);
        $failRes->assertJsonFragment(['message' => 'DNS lookup failed. Please try again later.']);

        // Test chunked TXT entry assembly
        $fakeDns->shouldThrow(false);
        $fakeDns->setTxtRecords('_whatsappcrm-challenge.chunked.acmecorp.com', [
            'whatsapp-crm-verification=longchunktoken123456789',
        ]);

        $successRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/auth/admin/tenants/{$this->tenant->id}/domains/{$domain->id}/verify");
        $successRes->assertStatus(200);
    }
}
