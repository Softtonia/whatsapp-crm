<?php

namespace Tests\Feature;

use App\Models\AdminMfaChallenge;
use App\Models\AdminMfaRecoveryCode;
use App\Models\AdminMfaSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $regularUser;
    protected Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('agent', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'superadmin@example.com',
            'password' => Hash::make('SuperAdminPass123!'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->regularUser = User::factory()->create([
            'email' => 'regular@example.com',
            'password' => Hash::make('RegularPass123!'),
        ]);
        $this->regularUser->assignRole('agent');

        $this->google2fa = new Google2FA();
    }

    public function test_guest_cannot_enroll_mfa(): void
    {
        $response = $this->postJson('/api/auth/admin/mfa/enroll', [
            'password' => 'SuperAdminPass123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_non_super_admin_cannot_enroll_mfa(): void
    {
        $token = $this->regularUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/enroll', [
                'password' => 'RegularPass123!',
            ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_enroll_requires_valid_password(): void
    {
        $token = $this->superAdmin->createToken('admin-auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/enroll', [
                'password' => 'WrongPassword!',
            ]);

        $response->assertStatus(422);
    }

    public function test_super_admin_can_enroll_mfa(): void
    {
        $token = $this->superAdmin->createToken('admin-auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/enroll', [
                'password' => 'SuperAdminPass123!',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'secret',
            'provisioning_uri',
            'qr_code_svg',
        ]);

        // MFA should not be enabled yet until confirmed
        $setting = AdminMfaSetting::where('user_id', $this->superAdmin->id)->first();
        $this->assertNotNull($setting);
        $this->assertFalse($setting->is_enabled);
        $this->assertNull($setting->confirmed_at);
    }

    public function test_mfa_confirm_rejects_invalid_totp(): void
    {
        $token = $this->superAdmin->createToken('admin-auth-token')->plainTextToken;

        // Enroll first
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/enroll', [
                'password' => 'SuperAdminPass123!',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/confirm', [
                'code' => '000000',
            ]);

        $response->assertStatus(422);
        $setting = AdminMfaSetting::where('user_id', $this->superAdmin->id)->first();
        $this->assertFalse($setting->is_enabled);
    }

    public function test_mfa_confirm_activates_mfa_and_generates_recovery_codes(): void
    {
        $token = $this->superAdmin->createToken('admin-auth-token')->plainTextToken;

        // Enroll first
        $enrollRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/enroll', [
                'password' => 'SuperAdminPass123!',
            ]);

        $secret = $enrollRes->json('secret');
        $validCode = $this->google2fa->getCurrentOtp($secret);

        $confirmRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/confirm', [
                'code' => $validCode,
            ]);

        $confirmRes->assertStatus(200);
        $confirmRes->assertJsonStructure([
            'message',
            'recovery_codes',
            'warning',
        ]);

        $recoveryCodes = $confirmRes->json('recovery_codes');
        $this->assertCount(8, $recoveryCodes);

        $setting = AdminMfaSetting::where('user_id', $this->superAdmin->id)->first();
        $this->assertTrue($setting->is_enabled);
        $this->assertNotNull($setting->confirmed_at);

        // Verify recovery codes are stored hashed, not plaintext
        $this->assertEquals(8, AdminMfaRecoveryCode::where('user_id', $this->superAdmin->id)->count());
        $firstHash = AdminMfaRecoveryCode::where('user_id', $this->superAdmin->id)->first()->code_hash;
        $this->assertNotEquals($recoveryCodes[0], $firstHash);
    }

    public function test_login_for_mfa_enabled_admin_returns_challenge_not_token(): void
    {
        // Setup confirmed MFA for superAdmin
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'superadmin@example.com',
            'password' => 'SuperAdminPass123!',
        ]);

        $loginRes->assertStatus(200);
        $loginRes->assertJsonFragment(['mfa_required' => true]);
        $loginRes->assertJsonStructure([
            'message',
            'mfa_required',
            'challenge_token',
            'expires_at',
        ]);
        $this->assertArrayNotHasKey('token', $loginRes->json());

        // Verify challenge token does NOT work as a bearer token
        $challengeToken = $loginRes->json('challenge_token');
        $unauthorizedRes = $this->withHeader('Authorization', 'Bearer ' . $challengeToken)
            ->getJson('/api/auth/admin/me');
        $unauthorizedRes->assertStatus(401);
    }

    public function test_mfa_verify_with_valid_totp_completes_login(): void
    {
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'superadmin@example.com',
            'password' => 'SuperAdminPass123!',
        ]);

        $challengeToken = $loginRes->json('challenge_token');
        $validCode = $this->google2fa->getCurrentOtp($secret);

        $verifyRes = $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ]);

        $verifyRes->assertStatus(200);
        $verifyRes->assertJsonStructure([
            'message',
            'token',
            'session_id',
            'role',
        ]);

        $token = $verifyRes->json('token');
        $this->assertNotEmpty($token);

        // Verify authenticated request works with new token
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/me');
        $meRes->assertStatus(200);
        $meRes->assertJsonPath('data.email', 'superadmin@example.com');
    }

    public function test_mfa_challenge_cannot_be_reused(): void
    {
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'superadmin@example.com',
            'password' => 'SuperAdminPass123!',
        ]);

        $challengeToken = $loginRes->json('challenge_token');
        $validCode = $this->google2fa->getCurrentOtp($secret);

        // First use
        $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ])->assertStatus(200);

        // Second use (replay)
        $replayRes = $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ]);

        $replayRes->assertStatus(422);
        $replayRes->assertJsonFragment(['message' => 'The MFA challenge has already been used.']);
    }

    public function test_mfa_challenge_expires_after_5_minutes(): void
    {
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        $challenge = AdminMfaChallenge::create([
            'user_id' => $this->superAdmin->id,
            'challenge_token' => 'expired-challenge-token',
            'attempts' => 0,
            'expires_at' => now()->subMinute(),
        ]);

        $validCode = $this->google2fa->getCurrentOtp($secret);

        $verifyRes = $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => 'expired-challenge-token',
            'code' => $validCode,
        ]);

        $verifyRes->assertStatus(422);
        $verifyRes->assertJsonFragment(['message' => 'The MFA challenge has expired. Please log in again.']);
    }

    public function test_mfa_verify_with_recovery_code_succeeds_once_and_fails_on_reuse(): void
    {
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        $recoveryCode = 'ABCD1-EFGH2';
        AdminMfaRecoveryCode::create([
            'user_id' => $this->superAdmin->id,
            'code_hash' => Hash::make($recoveryCode),
            'used_at' => null,
        ]);

        $challenge1 = AdminMfaChallenge::create([
            'user_id' => $this->superAdmin->id,
            'challenge_token' => 'challenge-rec-1',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        // First verify with recovery code
        $res1 = $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => 'challenge-rec-1',
            'recovery_code' => $recoveryCode,
        ]);

        $res1->assertStatus(200);
        $res1->assertJsonStructure(['token', 'session_id']);

        // Verify recovery code is now marked used
        $codeRecord = AdminMfaRecoveryCode::where('user_id', $this->superAdmin->id)->first();
        $this->assertNotNull($codeRecord->used_at);

        // Attempt second login with same recovery code on a new challenge
        $challenge2 = AdminMfaChallenge::create([
            'user_id' => $this->superAdmin->id,
            'challenge_token' => 'challenge-rec-2',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $res2 = $this->postJson('/api/auth/admin/mfa/verify', [
            'challenge_token' => 'challenge-rec-2',
            'recovery_code' => $recoveryCode,
        ]);

        $res2->assertStatus(422);
    }

    public function test_disable_mfa_requires_reauthentication_and_valid_code(): void
    {
        $secret = $this->google2fa->generateSecretKey(32);
        AdminMfaSetting::create([
            'user_id' => $this->superAdmin->id,
            'totp_secret_encrypted' => Crypt::encryptString($secret),
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        AdminMfaRecoveryCode::create([
            'user_id' => $this->superAdmin->id,
            'code_hash' => Hash::make('TEST1-CODE2'),
            'used_at' => null,
        ]);

        $token = $this->superAdmin->createToken('admin-auth-token')->plainTextToken;

        // Incorrect password
        $failRes1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/disable', [
                'password' => 'WrongPass',
                'code' => $this->google2fa->getCurrentOtp($secret),
            ]);
        $failRes1->assertStatus(422);

        // Incorrect TOTP code
        $failRes2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/disable', [
                'password' => 'SuperAdminPass123!',
                'code' => '999999',
            ]);
        $failRes2->assertStatus(422);

        // Valid disable
        $validCode = $this->google2fa->getCurrentOtp($secret);
        $successRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/mfa/disable', [
                'password' => 'SuperAdminPass123!',
                'code' => $validCode,
            ]);

        $successRes->assertStatus(200);
        $successRes->assertJsonFragment(['message' => 'MFA has been successfully disabled.']);

        $setting = AdminMfaSetting::where('user_id', $this->superAdmin->id)->first();
        $this->assertFalse($setting->is_enabled);
        $this->assertNull($setting->confirmed_at);
        $this->assertNull($setting->totp_secret_encrypted);

        // Recovery codes should be cleaned up
        $this->assertEquals(0, AdminMfaRecoveryCode::where('user_id', $this->superAdmin->id)->count());
    }
}
