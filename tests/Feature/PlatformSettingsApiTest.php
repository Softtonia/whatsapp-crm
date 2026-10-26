<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $settingsAdmin;
    protected User $unauthorizedAdmin;
    protected Role $superAdminRole;
    protected Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superAdminRole = Role::firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['slug' => 'super_admin', 'is_default' => true]
        );

        $this->adminRole = Role::firstOrCreate(
            ['name' => 'Administrator', 'guard_name' => 'web'],
            ['slug' => 'administrator', 'is_default' => true]
        );

        $perms = [
            'settings.read',
            'settings.manage',
            'integrations.read',
            'integrations.manage',
            'security.manage',
        ];

        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $this->superAdminRole->syncPermissions(Permission::all());

        $this->superAdmin = User::factory()->create([
            'email' => 'superadmin-settings@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
        $this->superAdmin->assignRole($this->superAdminRole);

        $this->settingsAdmin = User::factory()->create([
            'email' => 'settings-admin@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
        $this->settingsAdmin->assignRole($this->adminRole);
        $this->settingsAdmin->givePermissionTo(['settings.read', 'settings.manage', 'integrations.read', 'integrations.manage']);

        $this->unauthorizedAdmin = User::factory()->create([
            'email' => 'no-settings@example.com',
            'password' => Hash::make('Secret123!'),
            'status' => 'active',
        ]);
        $this->unauthorizedAdmin->assignRole($this->adminRole);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * SET-001 — Valid update: A permitted admin updates a valid general setting.
     */
    public function test_set_001_permitted_admin_updates_valid_general_setting(): void
    {
        Sanctum::actingAs($this->settingsAdmin, ['*']);

        $response = $this->patchJson('/api/auth/admin/settings/general', [
            'platform_name' => 'Acme Enterprise CRM',
            'support_email' => 'support@acmecrm.com',
            'default_timezone' => 'America/New_York',
            'default_currency' => 'USD',
            'maintenance_mode' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform_name', 'Acme Enterprise CRM')
            ->assertJsonPath('data.support_email', 'support@acmecrm.com')
            ->assertJsonPath('data.default_timezone', 'America/New_York')
            ->assertJsonPath('data.default_currency', 'USD');

        // Verify database persistence
        $this->assertDatabaseHas('platform_settings', [
            'key' => 'platform_name',
            'value' => 'Acme Enterprise CRM',
        ]);

        // Verify audit logging
        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'platform.settings.general_updated',
            'category' => 'settings',
        ]);

        // Verify subsequent GET returns the persisted data
        $getRes = $this->getJson('/api/auth/admin/settings');
        $getRes->assertStatus(200)
            ->assertJsonPath('data.platform_name', 'Acme Enterprise CRM')
            ->assertJsonPath('data.default_currency', 'USD');
    }

    /**
     * SET-002 — Unknown key: Submit an unsupported setting key, verify rejection.
     */
    public function test_set_002_rejects_unknown_or_invalid_general_keys(): void
    {
        Sanctum::actingAs($this->settingsAdmin, ['*']);

        // Test invalid timezone
        $resTimezone = $this->patchJson('/api/auth/admin/settings/general', [
            'default_timezone' => 'Invalid/Timezone_Not_Exists',
        ]);
        $resTimezone->assertStatus(422)
            ->assertJsonValidationErrors(['default_timezone']);

        // Test invalid currency
        $resCurrency = $this->patchJson('/api/auth/admin/settings/general', [
            'default_currency' => 'BITCOIN_INVALID',
        ]);
        $resCurrency->assertStatus(422)
            ->assertJsonValidationErrors(['default_currency']);

        // Test invalid email
        $resEmail = $this->patchJson('/api/auth/admin/settings/general', [
            'support_email' => 'not-an-email-address',
        ]);
        $resEmail->assertStatus(422)
            ->assertJsonValidationErrors(['support_email']);

        // Test unknown general key rejected (SET-002)
        $resUnknownGeneral = $this->patchJson('/api/auth/admin/settings/general', [
            'unsupported_custom_key' => 'arbitrary_value',
            'platform_name' => 'Should Not Save',
        ]);
        $resUnknownGeneral->assertStatus(422);
        $this->assertDatabaseMissing('platform_settings', [
            'value' => 'Should Not Save',
        ]);

        // Test unknown provider key rejected (SET-002)
        $resUnknownProvider = $this->patchJson('/api/auth/admin/settings/integrations/whatsapp', [
            'unsupported_meta_field' => 'bad_data',
        ]);
        $resUnknownProvider->assertStatus(422);

        // Test unknown security key rejected (SET-002)
        Sanctum::actingAs($this->superAdmin, ['*']);
        $resUnknownSecurity = $this->patchJson('/api/auth/admin/settings/security', [
            'unsupported_security_flag' => true,
        ]);
        $resUnknownSecurity->assertStatus(422);
    }

    /**
     * SET-003 — Secret masking: Update an integration credential, verify encryption and masking.
     */
    public function test_set_003_secret_masking_and_encryption_for_integrations(): void
    {
        Sanctum::actingAs($this->settingsAdmin, ['*']);

        // 1. Update WhatsApp secrets
        $resWa = $this->patchJson('/api/auth/admin/settings/integrations/whatsapp', [
            'app_secret' => 'RawSensitiveMetaAppSecret12345',
            'verify_token' => 'RawSensitiveVerifyToken99999',
            'graph_version' => 'v23.0',
        ]);

        $resWa->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_app_secret', true)
            ->assertJsonPath('data.has_verify_token', true);

        $waContent = $resWa->getContent();
        $this->assertStringNotContainsString('RawSensitiveMetaAppSecret12345', $waContent);
        $this->assertStringNotContainsString('RawSensitiveVerifyToken99999', $waContent);

        // 2. Update Razorpay secrets
        $resRzp = $this->patchJson('/api/auth/admin/settings/integrations/razorpay', [
            'is_active' => true,
            'mode' => 'live',
            'key_id' => 'rzp_live_51234567890abcdef',
            'key_secret' => 'RawSensitiveRazorpayKeySecret9999',
            'webhook_secret' => 'RawSensitiveRazorpayWebhookSecret8888',
        ]);

        $resRzp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_key_secret', true)
            ->assertJsonPath('data.has_webhook_secret', true);

        $rzpContent = $resRzp->getContent();
        $this->assertStringNotContainsString('RawSensitiveRazorpayKeySecret9999', $rzpContent);
        $this->assertStringNotContainsString('RawSensitiveRazorpayWebhookSecret8888', $rzpContent);

        // 3. Verify GET /settings/integrations does NOT expose secrets
        $resGet = $this->getJson('/api/auth/admin/settings/integrations');
        $resGet->assertStatus(200);
        $getContent = $resGet->getContent();
        $this->assertStringNotContainsString('RawSensitiveMetaAppSecret12345', $getContent);
        $this->assertStringNotContainsString('RawSensitiveRazorpayKeySecret9999', $getContent);

        // 4. Verify raw values stored encrypted in database
        $secretRecord = PlatformSetting::where('key', 'razorpay_key_secret')->first();
        $this->assertNotNull($secretRecord);
        $this->assertNotEquals('RawSensitiveRazorpayKeySecret9999', $secretRecord->value); // value is encrypted
        $this->assertEquals('RawSensitiveRazorpayKeySecret9999', $secretRecord->typed_value); // decrypted getter
    }

    /**
     * SET-004 — Concurrent edit / partial update: update one setting while preserving unrelated settings.
     */
    public function test_set_004_partial_updates_preserve_unrelated_settings(): void
    {
        Sanctum::actingAs($this->settingsAdmin, ['*']);

        // Set initial values
        $this->patchJson('/api/auth/admin/settings/general', [
            'platform_name' => 'Initial Brand',
            'support_email' => 'initial@brand.com',
            'default_currency' => 'INR',
        ]);

        // Partial update: only update support_email
        $partialRes = $this->patchJson('/api/auth/admin/settings/general', [
            'support_email' => 'updated@brand.com',
        ]);

        $partialRes->assertStatus(200)
            ->assertJsonPath('data.support_email', 'updated@brand.com')
            ->assertJsonPath('data.platform_name', 'Initial Brand') // Preserved
            ->assertJsonPath('data.default_currency', 'INR'); // Preserved

        // Integration partial update: updating mode preserves key_secret
        $this->patchJson('/api/auth/admin/settings/integrations/razorpay', [
            'key_id' => 'rzp_test_persisted_id',
            'key_secret' => 'SecretToPreserve',
        ]);

        $partialInt = $this->patchJson('/api/auth/admin/settings/integrations/razorpay', [
            'mode' => 'live',
        ]);

        $partialInt->assertStatus(200)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.has_key_secret', true); // Preserved
    }

    /**
     * SET-005 — Unauthorized security change: Unauthenticated and unauthorized users cannot update security settings.
     */
    public function test_set_005_unauthorized_security_changes_rejected(): void
    {
        // 1. Unauthenticated request
        $unauthRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
        ]);
        $unauthRes->assertStatus(401);

        // 2. User without security.manage
        Sanctum::actingAs($this->settingsAdmin, ['*']); // has settings.manage, NOT security.manage

        $forbiddenRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
        ]);
        $forbiddenRes->assertStatus(403);

        // 3. User with Super Admin role can update security settings
        Sanctum::actingAs($this->superAdmin, ['*']);

        $successRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'mfa_enforced' => true,
            'login_max_attempts' => 10,
        ]);

        $successRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_timeout_minutes', 60)
            ->assertJsonPath('data.mfa_enforced', true)
            ->assertJsonPath('data.login_max_attempts', 10);

        // Verify audit log
        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'platform.settings.security_updated',
            'category' => 'security',
        ]);
    }

    /**
     * Test bounds validation on security settings.
     */
    public function test_security_settings_validation_bounds(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // Session timeout too low (< 15 mins)
        $resLow = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 5,
        ]);
        $resLow->assertStatus(422)
            ->assertJsonValidationErrors(['session_timeout_minutes']);

        // Max attempts too high (> 20)
        $resHigh = $this->patchJson('/api/auth/admin/settings/security', [
            'login_max_attempts' => 999,
        ]);
        $resHigh->assertStatus(422)
            ->assertJsonValidationErrors(['login_max_attempts']);
    }

    /**
     * Test unsupported integration provider returns 404.
     */
    public function test_unsupported_integration_provider_returns_404(): void
    {
        Sanctum::actingAs($this->settingsAdmin, ['*']);

        $response = $this->patchJson('/api/auth/admin/settings/integrations/unsupported_gateway_xyz', [
            'api_key' => '123',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    /**
     * Test reauthentication verification via AdminSecurityOtpService token and single-use enforcement.
     */
    public function test_reauth_token_verification_and_single_use(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);

        // 1. Submit invalid token -> rejected
        $badTokenRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 45,
            'reauth_token' => 'invalid_or_fake_token_123',
        ]);
        $badTokenRes->assertStatus(422)
            ->assertJsonValidationErrors(['reauth_token']);

        // Settings should remain unchanged
        $this->assertDatabaseMissing('platform_settings', [
            'key' => 'session_timeout_minutes',
            'value' => '45',
        ]);

        // 2. Issue and verify a genuine challenge using AdminSecurityOtpService
        $otpService = app(\App\Services\AdminSecurityOtpService::class);
        $challenge = \App\Models\AdminSecurityChallenge::create([
            'user_id' => $this->superAdmin->id,
            'purpose' => 'security_settings_update',
            'target_email' => $this->superAdmin->email,
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        // Verify OTP to get single-use auth_token
        $verified = $otpService->verifyOtp($this->superAdmin, 'security_settings_update', '123456');
        $authToken = $verified['data']['auth_token'];
        $this->assertNotEmpty($authToken);

        // 3. Apply security update with the valid reauth_token
        $goodRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 45,
            'reauth_token' => $authToken,
        ]);
        $goodRes->assertStatus(200)
            ->assertJsonPath('data.session_timeout_minutes', 45);

        // 5. Test expired token
        $expiredChallenge = \App\Models\AdminSecurityChallenge::create([
            'user_id' => $this->superAdmin->id,
            'purpose' => 'security_settings_update',
            'target_email' => $this->superAdmin->email,
            'otp_hash' => Hash::make('654321'),
            'verified_at' => now()->subMinutes(15),
            'auth_token_hash' => Hash::make('expired_token_12345'),
            'auth_token_expires_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ]);

        $expiredRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'reauth_token' => 'expired_token_12345',
        ]);
        $expiredRes->assertStatus(422)
            ->assertJsonValidationErrors(['reauth_token']);

        // 6. Test wrong-user token (belongs to another admin)
        $otherChallenge = \App\Models\AdminSecurityChallenge::create([
            'user_id' => $this->settingsAdmin->id,
            'purpose' => 'security_settings_update',
            'target_email' => $this->settingsAdmin->email,
            'otp_hash' => Hash::make('111222'),
            'verified_at' => now(),
            'auth_token_hash' => Hash::make('other_admin_token_xyz'),
            'auth_token_expires_at' => now()->addMinutes(10),
            'expires_at' => now()->addMinutes(10),
        ]);

        $wrongUserRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'reauth_token' => 'other_admin_token_xyz',
        ]);
        $wrongUserRes->assertStatus(422)
            ->assertJsonValidationErrors(['reauth_token']);

        // 7. Test wrong-purpose token (e.g. purpose was 'email_change' instead of security_settings_update)
        $wrongPurposeChallenge = \App\Models\AdminSecurityChallenge::create([
            'user_id' => $this->superAdmin->id,
            'purpose' => 'email_change',
            'target_email' => 'new-email@example.com',
            'otp_hash' => Hash::make('333444'),
            'verified_at' => now(),
            'auth_token_hash' => Hash::make('email_change_token_abc'),
            'auth_token_expires_at' => now()->addMinutes(10),
            'expires_at' => now()->addMinutes(10),
        ]);

        $wrongPurposeRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'reauth_token' => 'email_change_token_abc',
        ]);
        $wrongPurposeRes->assertStatus(422)
            ->assertJsonValidationErrors(['reauth_token']);

        // 8. Test password fallback verification
        $badPassRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'current_password' => 'WrongPassword123!',
        ]);
        $badPassRes->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $goodPassRes = $this->patchJson('/api/auth/admin/settings/security', [
            'session_timeout_minutes' => 60,
            'current_password' => 'Secret123!',
        ]);
        $goodPassRes->assertStatus(200)
            ->assertJsonPath('data.session_timeout_minutes', 60);
    }

    /**
     * Test dynamic login rate limiting: Changing login_max_attempts alters actual login throttle limits.
     */
    public function test_dynamic_login_rate_limiting_enforcement(): void
    {
        // 1. Configure login_max_attempts to 3 via security settings
        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->patchJson('/api/auth/admin/settings/security', [
            'login_max_attempts' => 3,
            'login_decay_minutes' => 1,
            'current_password' => 'Secret123!',
        ])->assertStatus(200);

        \Illuminate\Support\Facades\RateLimiter::clear('admin_login:' . strtolower($this->superAdmin->email) . '|127.0.0.1');

        // Make 3 failed login attempts
        for ($i = 0; $i < 3; $i++) {
            $res = $this->postJson('/api/auth/admin/login', [
                'email' => $this->superAdmin->email,
                'password' => 'WrongPass!',
            ]);
            $res->assertStatus(422);
        }

        // 4th attempt must be throttled with 429
        $throttledRes = $this->postJson('/api/auth/admin/login', [
            'email' => $this->superAdmin->email,
            'password' => 'WrongPass!',
        ]);
        $throttledRes->assertStatus(429)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Too many login attempts'));
    }

    /**
     * Test global MFA enforcement: Admins without enrolled MFA are prompted to enroll rather than locked out.
     */
    public function test_global_mfa_enforcement_prompts_enrollment_without_lockout(): void
    {
        // Ensure superAdmin has NO confirmed MFA
        \App\Models\AdminMfaSetting::where('user_id', $this->superAdmin->id)->delete();

        // 1. Turn on global MFA enforcement
        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->patchJson('/api/auth/admin/settings/security', [
            'mfa_enforced' => true,
            'current_password' => 'Secret123!',
        ])->assertStatus(200);

        // 2. Login as superAdmin (who has not enrolled yet)
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => $this->superAdmin->email,
            'password' => 'Secret123!',
        ]);

        $loginRes->assertStatus(200)
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_enrollment_required', true)
            ->assertJsonStructure([
                'message',
                'mfa_required',
                'mfa_enrollment_required',
                'secret',
                'provisioning_uri',
                'qr_code_svg',
            ]);
    }

    /**
     * Test maintenance mode: Blocks tenant routes with 503 while preserving central admin and recovery access.
     */
    public function test_maintenance_mode_blocks_tenant_routes_while_preserving_admin(): void
    {
        // 1. Enable maintenance mode via general settings
        Sanctum::actingAs($this->settingsAdmin, ['*']);
        $this->patchJson('/api/auth/admin/settings/general', [
            'maintenance_mode' => true,
        ])->assertStatus(200);

        // Create a verified custom domain for a tenant
        $tenant = \App\Models\Tenant::create([
            'company_name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'email' => 'acme@example.com',
            'status' => 'active',
        ]);

        \App\Models\TenantCustomDomain::create([
            'tenant_id' => $tenant->id,
            'hostname' => 'tenant.example.com',
            'is_active' => true,
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verification_hostname' => '_challenge.tenant.example.com',
            'verification_record_type' => 'TXT',
            'verification_token' => 'test-token',
            'expected_record_value' => 'whatsapp-crm-verification=test-token',
        ]);

        // 2. Request tenant route on custom domain -> 503 Service Unavailable
        $tenantRes = $this->getJson('http://tenant.example.com/');
        $tenantRes->assertStatus(503)
            ->assertJsonPath('error', 'platform_maintenance');

        // 3. Central admin endpoints remain functional for administrative recovery
        $adminRes = $this->getJson('/api/auth/admin/settings');
        $adminRes->assertStatus(200);

        // Disable maintenance mode
        $this->patchJson('/api/auth/admin/settings/general', [
            'maintenance_mode' => false,
        ])->assertStatus(200);

        // Tenant route is accessible again
        $tenantResAfter = $this->getJson('http://tenant.example.com/');
        $tenantResAfter->assertStatus(200);
    }
}

