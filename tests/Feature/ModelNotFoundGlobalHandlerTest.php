<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModelNotFoundGlobalHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_existent_model_returns_clean_json_error(): void
    {
        Role::findOrCreate('Super Admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/admin/tenants/999999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Selected Tenant does not exist.',
            'error' => 'Record not found.',
        ]);
    }

    public function test_non_existent_contact_returns_clean_json_error(): void
    {
        Role::findOrCreate('Super Admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/admin/contacts/999999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Selected Contact does not exist.',
            'error' => 'Record not found.',
        ]);
    }
}
