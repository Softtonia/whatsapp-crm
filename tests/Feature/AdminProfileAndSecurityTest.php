<?php

namespace Tests\Feature;

use App\Mail\AdminSecurityOtpMail;
use App\Models\ActivityLog;
use App\Models\AdminSecurityChallenge;
use App\Models\TenantActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProfileAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $otherAdmin;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        $this->superAdmin = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'admin@example.com',
            'phone_number' => '+1234567890',
            'password' => Hash::make('CurrentSecret123!'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->otherAdmin = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'other@example.com',
            'phone_number' => '+1987654321',
            'password' => Hash::make('OtherSecret123!'),
        ]);
        $this->otherAdmin->assignRole('Super Admin');

        $this->regularUser = User::factory()->create([
            'first_name' => 'Bob',
            'last_name' => 'User',
            'email' => 'bob@example.com',
            'phone_number' => '+1122334455',
            'password' => Hash::make('UserSecret123!'),
        ]);
    }

    /* -------------------------------------------------------------------------
     * PROFILE TESTS
     * ------------------------------------------------------------------------- */

    public function test_authenticated_super_admin_can_retrieve_own_profile(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson('/api/auth/admin/profile');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => [
                'id' => $this->superAdmin->id,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'admin@example.com',
                'phone_number' => '+1234567890',
            ],
        ]);
    }

    public function test_unauthenticated_and_non_admin_cannot_access_profile(): void
    {
        // Unauthenticated
        $this->getJson('/api/auth/admin/profile')->assertStatus(401);

        // Non Super Admin
        Sanctum::actingAs($this->regularUser);
        $this->getJson('/api/auth/admin/profile')->assertStatus(403);
    }

    public function test_valid_profile_update_succeeds_via_put_and_patch(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // PUT full update
        $responsePut = $this->putJson('/api/auth/admin/profile', [
            'first_name' => 'Johnny',
            'last_name' => 'Doe Updated',
            'phone_number' => '+1000000001',
        ]);
        $responsePut->assertStatus(200);
        $responsePut->assertJsonPath('data.first_name', 'Johnny');
        $responsePut->assertJsonPath('data.phone_number', '+1000000001');

        $this->assertDatabaseHas('users', [
            'id' => $this->superAdmin->id,
            'first_name' => 'Johnny',
            'phone_number' => '+1000000001',
        ]);

        // PATCH partial update
        $responsePatch = $this->patchJson('/api/auth/admin/profile', [
            'last_name' => 'Depp',
        ]);
        $responsePatch->assertStatus(200);
        $responsePatch->assertJsonPath('data.last_name', 'Depp');

        // Verify activity_logs recorded event
        $this->assertDatabaseHas('activity_logs', [
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'profile.updated',
            'category' => 'profile',
        ]);
    }

    public function test_email_cannot_be_updated_via_profile_update(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->putJson('/api/auth/admin/profile', [
            'first_name' => 'Johnny',
            'phone_number' => '+1000000001',
            'email' => 'bypass@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);

        // Assert email remained unchanged
        $this->assertEquals('admin@example.com', $this->superAdmin->fresh()->email);
    }

    public function test_profile_update_rejects_duplicate_phone_number_of_another_user(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->patchJson('/api/auth/admin/profile', [
            'phone_number' => $this->otherAdmin->phone_number,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone_number']);
    }

    /* -------------------------------------------------------------------------
     * PASSWORD CHANGE VIA OTP TESTS
     * ------------------------------------------------------------------------- */

    public function test_password_change_otp_is_sent_to_registered_email_and_not_in_response(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson('/api/auth/admin/change-password/send-otp');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Password change OTP sent to your registered email address.',
        ]);

        // Never expose OTP in response
        $this->assertArrayNotHasKey('otp', $response->json());
        $this->assertArrayNotHasKey('otp', $response->json('data') ?? []);

        Mail::assertSent(AdminSecurityOtpMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com') && $mail->purpose === 'password_change';
        });

        $this->assertDatabaseHas('admin_security_challenges', [
            'user_id' => $this->superAdmin->id,
            'purpose' => 'password_change',
            'target_email' => 'admin@example.com',
        ]);
    }

    public function test_password_change_verify_otp_and_change_workflow(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // 1. Request OTP
        $this->postJson('/api/auth/admin/change-password/send-otp')->assertStatus(200);

        $challenge = AdminSecurityChallenge::query()
            ->where('user_id', $this->superAdmin->id)
            ->where('purpose', 'password_change')
            ->firstOrFail();

        $rawOtp = Crypt::decryptString($challenge->otp_encrypted);

        // 2. Verify with wrong OTP fails
        $wrongResponse = $this->postJson('/api/auth/admin/change-password/verify-otp', [
            'otp' => '000000',
        ]);
        $wrongResponse->assertStatus(422);

        // 3. Verify with correct OTP succeeds and returns auth_token
        $verifyResponse = $this->postJson('/api/auth/admin/change-password/verify-otp', [
            'otp' => $rawOtp,
        ]);
        $verifyResponse->assertStatus(200);
        $authToken = $verifyResponse->json('data.auth_token');
        $this->assertNotEmpty($authToken);

        // 4. Change password with weak / mismatched confirmation fails
        $badPwResponse = $this->postJson('/api/auth/admin/change-password', [
            'auth_token' => $authToken,
            'new_password' => 'short',
            'new_password_confirmation' => 'mismatch',
        ]);
        $badPwResponse->assertStatus(422);

        // 4b. Changing password to current/old password fails
        $samePwResponse = $this->postJson('/api/auth/admin/change-password', [
            'auth_token' => $authToken,
            'new_password' => 'CurrentSecret123!',
            'new_password_confirmation' => 'CurrentSecret123!',
        ]);
        $samePwResponse->assertStatus(422);
        $samePwResponse->assertJsonValidationErrors(['new_password']);
        $samePwResponse->assertJsonFragment([
            'message' => 'The new password cannot be the same as your current password. Please choose a different password.',
        ]);

        // 5. Change password successfully
        $changeResponse = $this->postJson('/api/auth/admin/change-password', [
            'auth_token' => $authToken,
            'new_password' => 'BrandNewPassword999!',
            'new_password_confirmation' => 'BrandNewPassword999!',
        ]);
        $changeResponse->assertStatus(200);
        $changeResponse->assertJson(['success' => true]);

        // Verify password hash updated in DB
        $this->assertTrue(Hash::check('BrandNewPassword999!', $this->superAdmin->fresh()->password));

        // 6. Token reuse fails atomically
        $reuseResponse = $this->postJson('/api/auth/admin/change-password', [
            'auth_token' => $authToken,
            'new_password' => 'AnotherNewPassword888!',
            'new_password_confirmation' => 'AnotherNewPassword888!',
        ]);
        $reuseResponse->assertStatus(422);

        // Verify event in activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'password.changed',
            'category' => 'security',
        ]);
    }

    /* -------------------------------------------------------------------------
     * EMAIL CHANGE VIA OTP TESTS
     * ------------------------------------------------------------------------- */

    public function test_email_change_validates_syntax_and_uniqueness(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Same email rejected
        $sameResponse = $this->postJson('/api/auth/admin/change-email/send-otp', [
            'new_email' => 'admin@example.com',
        ]);
        $sameResponse->assertStatus(422);

        // Existing email of other admin rejected
        $dupResponse = $this->postJson('/api/auth/admin/change-email/send-otp', [
            'new_email' => 'other@example.com',
        ]);
        $dupResponse->assertStatus(422);

        // Invalid syntax rejected
        $invalidResponse = $this->postJson('/api/auth/admin/change-email/send-otp', [
            'new_email' => 'not-an-email',
        ]);
        $invalidResponse->assertStatus(422);
    }

    public function test_email_change_workflow_succeeds_and_updates_email(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $newEmail = 'newadmin@corporation.com';

        // 1. Request OTP to new email
        $sendResponse = $this->postJson('/api/auth/admin/change-email/send-otp', [
            'new_email' => $newEmail,
        ]);
        $sendResponse->assertStatus(200);

        // Assert mail was sent to new email address
        Mail::assertSent(AdminSecurityOtpMail::class, function ($mail) use ($newEmail) {
            return $mail->hasTo($newEmail) && $mail->purpose === 'email_change';
        });

        // Current email remains untouched before verification
        $this->assertEquals('admin@example.com', $this->superAdmin->fresh()->email);

        $challenge = AdminSecurityChallenge::query()
            ->where('user_id', $this->superAdmin->id)
            ->where('purpose', 'email_change')
            ->where('target_email', $newEmail)
            ->firstOrFail();

        $rawOtp = Crypt::decryptString($challenge->otp_encrypted);

        // 2. Verify OTP
        $verifyResponse = $this->postJson('/api/auth/admin/change-email/verify-otp', [
            'new_email' => $newEmail,
            'otp' => $rawOtp,
        ]);
        $verifyResponse->assertStatus(200);
        $authToken = $verifyResponse->json('data.auth_token');
        $this->assertNotEmpty($authToken);

        // 3. Commit email change
        $commitResponse = $this->postJson('/api/auth/admin/change-email', [
            'new_email' => $newEmail,
            'auth_token' => $authToken,
        ]);
        $commitResponse->assertStatus(200);
        $commitResponse->assertJsonPath('data.email', $newEmail);

        // User email updated and email_verified_at set
        $fresh = $this->superAdmin->fresh();
        $this->assertEquals($newEmail, $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);

        // Token reuse fails
        $this->postJson('/api/auth/admin/change-email', [
            'new_email' => $newEmail,
            'auth_token' => $authToken,
        ])->assertStatus(422);

        // Verify activity_logs recorded event
        $this->assertDatabaseHas('activity_logs', [
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'email.changed',
            'category' => 'security',
        ]);
    }

    /* -------------------------------------------------------------------------
     * DEDICATED ACTIVITY LOGS TESTS
     * ------------------------------------------------------------------------- */

    public function test_activity_logs_stored_strictly_in_activity_logs_and_never_in_tenant_activity_logs(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Perform profile update which logs an event
        $this->patchJson('/api/auth/admin/profile', [
            'first_name' => 'Super',
        ])->assertStatus(200);

        // Assert strictly written to activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'profile.updated',
        ]);

        // Assert NOT written to tenant_activity_logs
        $this->assertDatabaseMissing('tenant_activity_logs', [
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'profile.updated',
        ]);
    }

    public function test_activity_logs_can_be_retrieved_and_filtered(): void
    {
        Sanctum::actingAs($this->superAdmin);

        // Trigger two distinct events
        $this->patchJson('/api/auth/admin/profile', ['first_name' => 'Hero'])->assertStatus(200);
        $this->postJson('/api/auth/admin/change-password/send-otp')->assertStatus(200);

        // List logs
        $listResponse = $this->getJson('/api/auth/admin/activity-logs');
        $listResponse->assertStatus(200);
        $listResponse->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'actor_user_id',
                    'event_type',
                    'category',
                    'description',
                    'created_at',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
            ],
        ]);

        // Filter by category
        $filteredResponse = $this->getJson('/api/auth/admin/activity-logs?category=profile');
        $filteredResponse->assertStatus(200);
        foreach ($filteredResponse->json('data') as $log) {
            $this->assertEquals('profile', $log['category']);
        }

        // Single log retrieval
        $firstId = $listResponse->json('data.0.id');
        $singleResponse = $this->getJson("/api/auth/admin/activity-logs/{$firstId}");
        $singleResponse->assertStatus(200);
        $singleResponse->assertJsonPath('data.id', $firstId);

        // Non-admin access denied
        Sanctum::actingAs($this->regularUser);
        $this->getJson('/api/auth/admin/activity-logs')->assertStatus(403);
    }
}
