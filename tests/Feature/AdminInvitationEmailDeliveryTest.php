<?php

namespace Tests\Feature;

use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminInvitationEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $platformOwner;
    protected Role $platformOwnerRole;
    protected Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->platformOwnerRole = Role::firstOrCreate(
            ['name' => 'platform_owner', 'guard_name' => 'web'],
            ['slug' => 'platform_owner', 'is_default' => true]
        );

        $this->adminRole = Role::firstOrCreate(
            ['name' => 'Administrator', 'guard_name' => 'web'],
            ['slug' => 'administrator', 'is_default' => true]
        );

        $permissions = ['users.view', 'users.create', 'users.update', 'users.delete'];
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $allPerms = Permission::where('guard_name', 'web')->get();
        $this->platformOwnerRole->syncPermissions($allPerms);

        $this->platformOwner = User::firstOrCreate(
            ['email' => 'owner-mail-test@crm-platform.test'],
            [
                'first_name' => 'Platform',
                'last_name' => 'Owner',
                'phone_number' => '+1999888777',
                'password' => Hash::make('Secret123!'),
                'status' => 'active',
            ]
        );
        $this->platformOwner->syncRoles([$this->platformOwnerRole]);
    }

    /**
     * Test successful invitation email delivery: recipient, acceptance URL with raw token, expiry date.
     */
    public function test_invite_dispatches_email_with_secure_acceptance_url_and_expiry(): void
    {
        Mail::fake();

        config(['app.frontend_url' => 'https://app.whatsapp-crm.test']);

        Sanctum::actingAs($this->platformOwner, ['*']);

        $recipientEmail = 'invited-operator@crm-platform.test';

        $response = $this->postJson('/api/auth/admin/users/invite', [
            'email' => $recipientEmail,
            'role_name' => 'Administrator',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', $recipientEmail)
            ->assertJsonPath('data.role_name', 'Administrator')
            ->assertJsonPath('data.email_sent', true);

        // Verify AdminInvitation record created with hashed token, NOT raw token
        $invitation = AdminInvitation::where('email', $recipientEmail)->first();
        $this->assertNotNull($invitation);

        // Assert mail was sent to the validated recipient
        Mail::assertSent(AdminInvitationMail::class, function (AdminInvitationMail $mail) use ($recipientEmail, $invitation) {
            $this->assertTrue($mail->hasTo($recipientEmail));
            $this->assertEquals('Administrator', $mail->roleName);
            $this->assertStringContainsString('https://app.whatsapp-crm.test/accept-invitation?token=', $mail->acceptUrl);

            // Extract the raw token from the acceptance URL
            parse_str(parse_url($mail->acceptUrl, PHP_URL_QUERY), $queryParams);
            $rawTokenFromUrl = $queryParams['token'] ?? null;
            $this->assertNotEmpty($rawTokenFromUrl);

            // Verify that hashing the raw token matches the stored token_hash in DB
            $this->assertEquals(hash('sha256', $rawTokenFromUrl), $invitation->token_hash);
            // Verify raw token is never stored in DB
            $this->assertNotEquals($rawTokenFromUrl, $invitation->token_hash);

            // Verify expiry date is present in mailable
            $this->assertNotEmpty($mail->expiryDate);

            // Render email HTML and verify contents
            $rendered = $mail->render();
            $this->assertStringContainsString('Administrator Invitation', $rendered);
            $this->assertStringContainsString('https://app.whatsapp-crm.test/accept-invitation?token=', $rendered);

            return true;
        });
    }

    /**
     * Test email delivery failure handling: reports failure truthfully (500) and cleans up orphaned invitation.
     */
    public function test_invite_handles_email_delivery_failure_truthfully(): void
    {
        // Mock Mail to throw an exception simulating transport/SMTP error
        Mail::shouldReceive('to')
            ->once()
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new \Exception('Connection could not be established with host mailpit:1025'));

        Sanctum::actingAs($this->platformOwner, ['*']);

        $recipientEmail = 'fail-operator@crm-platform.test';

        $response = $this->postJson('/api/auth/admin/users/invite', [
            'email' => $recipientEmail,
            'role_name' => 'Administrator',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to deliver administrator invitation email. Please verify mail server settings and try again.');

        // Assert invitation was cleaned up and not left in an unusable dangling state
        $this->assertDatabaseMissing('admin_invitations', [
            'email' => $recipientEmail,
        ]);
    }

    /**
     * Test that production environment never exposes the raw token in API response.
     */
    public function test_raw_token_omitted_from_api_response_in_production(): void
    {
        Mail::fake();

        // Simulate production environment and debug false
        $originalEnv = app()->environment();
        config(['app.debug' => false]);
        app()->detectEnvironment(fn () => 'production');

        Sanctum::actingAs($this->platformOwner, ['*']);

        $response = $this->postJson('/api/auth/admin/users/invite', [
            'email' => 'prod-operator@crm-platform.test',
            'role_name' => 'Administrator',
        ]);

        // Restore environment
        app()->detectEnvironment(fn () => $originalEnv);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('invitation_token', $response->json('data'));
    }

    /**
     * Test single-use acceptance workflow with token from delivered email.
     */
    public function test_invitation_from_email_accepted_and_single_use(): void
    {
        Mail::fake();

        config(['app.frontend_url' => 'https://app.whatsapp-crm.test']);
        Sanctum::actingAs($this->platformOwner, ['*']);

        $recipientEmail = 'e2e-accept@crm-platform.test';

        $this->postJson('/api/auth/admin/users/invite', [
            'email' => $recipientEmail,
            'role_name' => 'Administrator',
        ])->assertStatus(201);

        $sentMail = null;
        Mail::assertSent(AdminInvitationMail::class, function ($mail) use (&$sentMail) {
            $sentMail = $mail;
            return true;
        });

        parse_str(parse_url($sentMail->acceptUrl, PHP_URL_QUERY), $queryParams);
        $rawToken = $queryParams['token'];

        // Accept invitation using token from email
        $acceptResponse = $this->postJson('/api/auth/admin/users/accept-invitation', [
            'token' => $rawToken,
            'first_name' => 'E2E',
            'last_name' => 'User',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $acceptResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $acceptedUser = User::where('email', $recipientEmail)->first();
        $this->assertNotNull($acceptedUser);
        $this->assertTrue($acceptedUser->hasRole('Administrator'));

        // Replay attempt rejected
        $replayResponse = $this->postJson('/api/auth/admin/users/accept-invitation', [
            'token' => $rawToken,
            'first_name' => 'E2E',
            'last_name' => 'User',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $replayResponse->assertStatus(409);
    }
}
