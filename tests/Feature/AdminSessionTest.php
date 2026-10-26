<?php

namespace Tests\Feature;

use App\Models\AdminSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminSecret123!'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->otherAdmin = User::factory()->create([
            'email' => 'other@example.com',
            'password' => Hash::make('OtherSecret123!'),
        ]);
        $this->otherAdmin->assignRole('Super Admin');
    }

    public function test_unauthenticated_request_cannot_list_sessions(): void
    {
        $response = $this->getJson('/api/auth/admin/sessions');
        $response->assertStatus(401);
    }

    public function test_login_creates_an_active_session_in_sessions_table(): void
    {
        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);

        $response->assertStatus(200);
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Assert session created in the default sessions table
        $this->assertDatabaseHas('sessions', [
            'user_id' => $this->superAdmin->id,
        ]);
    }

    public function test_admin_can_list_only_their_own_active_sessions(): void
    {
        // Login super admin
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);
        $token = $loginRes->json('token');

        // Create a session for other admin directly in sessions table
        AdminSession::create([
            'id' => 'other-session-uuid',
            'user_id' => $this->otherAdmin->id,
            'ip_address' => '10.0.0.99',
            'user_agent' => 'OtherBrowser/1.0',
            'payload' => base64_encode(json_encode(['device_label' => 'Other Browser on Linux'])),
            'last_activity' => time(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/sessions');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                '*' => [
                    'id',
                    'session_id',
                    'device_label',
                    'ip_address',
                    'user_agent',
                    'login_time',
                    'last_activity_at',
                    'is_current',
                ],
            ],
        ]);

        $sessionIds = collect($response->json('data'))->pluck('session_id')->all();
        $this->assertNotContains('other-session-uuid', $sessionIds);
    }

    public function test_admin_can_revoke_their_own_session(): void
    {
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);
        $token = $loginRes->json('token');

        $session = AdminSession::where('user_id', $this->superAdmin->id)->firstOrFail();
        $payload = app(\App\Services\AdminSessionService::class)->decodePayload($session->payload);
        $tokenId = $payload['personal_access_token_id'] ?? null;

        $revokeRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/auth/admin/sessions/' . $session->id);

        $revokeRes->assertStatus(200);
        $revokeRes->assertJsonFragment(['message' => 'Session revoked successfully.']);

        // Assert removed from sessions table
        $this->assertDatabaseMissing('sessions', [
            'id' => $session->id,
        ]);

        if ($tokenId) {
            $this->assertDatabaseMissing('personal_access_tokens', [
                'id' => $tokenId,
            ]);
        }

        // In test requests within the same PHP process, reset cached authenticated user on auth guard
        auth()->forgetGuards();

        // Subsequent requests with the revoked token should be rejected (token deleted)
        $subsequentRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/me');

        $subsequentRes->assertStatus(401);
    }

    public function test_admin_cannot_revoke_another_admins_session(): void
    {
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);
        $token = $loginRes->json('token');

        $otherSession = AdminSession::create([
            'id' => 'other-session-uuid-2',
            'user_id' => $this->otherAdmin->id,
            'ip_address' => '10.0.0.99',
            'user_agent' => 'OtherBrowser/1.0',
            'payload' => base64_encode(json_encode(['device_label' => 'Other Browser'])),
            'last_activity' => time(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/auth/admin/sessions/' . $otherSession->id);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'You are not authorized to revoke this session.']);

        $this->assertDatabaseHas('sessions', [
            'id' => 'other-session-uuid-2',
        ]);
    }

    public function test_logout_revokes_all_active_sessions(): void
    {
        $loginRes = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);
        $token = $loginRes->json('token');

        $logoutRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/admin/logout');

        $logoutRes->assertStatus(200);

        $activeCount = AdminSession::where('user_id', $this->superAdmin->id)->count();

        $this->assertEquals(0, $activeCount);

        // In test requests within the same PHP process, reset cached authenticated user on auth guard
        auth()->forgetGuards();

        $protectedRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/me');
        $protectedRes->assertStatus(401);
    }
}
