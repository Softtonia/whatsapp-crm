<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\BusinessWhatsAppAccount;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('owner', 'web');
        Role::findOrCreate('agent', 'web');
    }

    public function test_guest_cannot_access_protected_admin_endpoints(): void
    {
        $response = $this->getJson('/api/auth/admin/me');
        $response->assertStatus(401);

        $response = $this->getJson('/api/auth/admin/inbox/conversations');
        $response->assertStatus(401);
    }

    public function test_non_admin_cannot_login_via_admin_auth(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->postJson('/api/auth/admin/login', [
            'email' => 'regular@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_login_and_access_admin_endpoints(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminSecret123!'),
        ]);
        $admin->assignRole('super_admin');

        $loginResponse = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminSecret123!',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');
        $this->assertNotEmpty($token);

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/admin/me');

        $meResponse->assertStatus(200);
        $meResponse->assertJsonPath('data.email', 'admin@example.com');
    }

    public function test_webhook_verification(): void
    {
        Config::set('services.whatsapp.verify_token', 'test_verify_token');

        $response = $this->get('/api/v1/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=test_verify_token&hub.challenge=123456');

        $response->assertStatus(200);
        $this->assertSame('123456', $response->getContent());
    }

    public function test_webhook_rejects_missing_or_invalid_signature(): void
    {
        Config::set('services.whatsapp.app_secret', 'secret123');

        $response = $this->postJson('/api/v1/webhooks/meta/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ]);

        $response->assertStatus(401);
    }
}
