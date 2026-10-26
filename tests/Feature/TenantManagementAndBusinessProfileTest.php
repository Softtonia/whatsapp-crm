<?php


namespace Tests\Feature;


use App\Models\Tenant;

use App\Models\TenantUser;

use App\Models\TenantWhatsAppAccount;

use App\Models\User;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Illuminate\Support\Facades\Hash;

use Spatie\Permission\Models\Role;

use Tests\TestCase;

use App\Jobs\ProcessTenantBulkOperationJob;

use App\Models\TenantBulkOperation;

use Illuminate\Support\Facades\Queue;


class TenantManagementAndBusinessProfileTest extends TestCase

{

    use RefreshDatabase;


    protected User $superAdmin;

    protected User $owner;

    protected User $otherUser;


    protected function setUp(): void

    {

        parent::setUp();


        Role::findOrCreate('Super Admin', 'web');

        Role::findOrCreate('super_admin', 'web');

        Role::findOrCreate('owner', 'web');

        Role::findOrCreate('agent', 'web');


        $this->superAdmin = User::factory()->create([

            'email' => 'superadmin@example.com',

            'password' => Hash::make('SuperSecret123!'),

        ]);

        $this->superAdmin->assignRole('Super Admin');


        $this->owner = User::factory()->create([

            'email' => 'owner@example.com',

            'password' => Hash::make('OwnerSecret123!'),

        ]);


        $this->otherUser = User::factory()->create([

            'email' => 'other@example.com',

            'password' => Hash::make('OtherSecret123!'),

        ]);

    }


    public function test_super_admin_can_list_all_tenants(): void

    {

        Tenant::create(['name' => 'Acme Corp', 'slug' => 'acme-corp', 'status' => 'active']);

        Tenant::create(['name' => 'Beta LLC', 'slug' => 'beta-llc', 'status' => 'inactive']);


        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson('/api/auth/admin/tenants');


        $response->assertStatus(200);

        $response->assertJsonCount(2, 'data');

    }


    public function test_can_create_tenant_with_automatic_unique_slug(): void

    {

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson('/api/auth/admin/tenants', [

                'name' => 'Test Tenant',

            ]);


        $response->assertStatus(201);

        $response->assertJsonPath('data.name', 'Test Tenant');

        $response->assertJsonPath('data.slug', 'test-tenant');

        $response->assertJsonPath('data.status', 'active');


        // Test slug uniqueness collision handling

        $response2 = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson('/api/auth/admin/tenants', [

                'name' => 'Test Tenant',

            ]);


        $response2->assertStatus(201);

        $response2->assertJsonPath('data.slug', 'test-tenant-2');

    }


    public function test_can_activate_suspend_and_archive_tenant(): void

    {

        $business = Tenant::create([

            'name' => 'Starlight',

            'slug' => 'starlight',

            'status' => 'active',

        ]);


        // Suspend with reason

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/suspend", [

                'suspend_reason' => 'Payment overdue for 30 days',

            ]);


        $response->assertStatus(200);


        $this->assertEquals('suspended', $business->fresh()->status);

        $this->assertEquals(

            'Payment overdue for 30 days',

            $business->fresh()->suspend_reason

        );


        // Archive

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/archive");


        $response->assertStatus(200);

        $this->assertEquals('archived', $business->fresh()->status);


        // Activate

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/activate", [

                'reason' => 'Tenant reactivated by admin',

            ]);


        $response->assertStatus(200);


        $this->assertEquals('active', $business->fresh()->status);

        $this->assertNull($business->fresh()->suspend_reason);


        // Verify audit logs for all three status changes.

        $this->assertDatabaseCount('tenant_status_history', 3);

        $this->assertDatabaseCount('tenant_activity_logs', 3);


        $this->assertDatabaseHas('tenant_status_history', [

            'tenant_id' => $business->id,

            'previous_status' => 'active',

            'new_status' => 'suspended',

            'performed_by' => $this->superAdmin->id,

        ]);


        $this->assertDatabaseHas('tenant_activity_logs', [

            'tenant_id' => $business->id,

            'actor_user_id' => $this->superAdmin->id,

            'event_type' => 'tenant.suspended',

        ]);


        $this->assertDatabaseHas('tenant_activity_logs', [

            'tenant_id' => $business->id,

            'event_type' => 'tenant.archived',

        ]);


        $this->assertDatabaseHas('tenant_activity_logs', [

            'tenant_id' => $business->id,

            'event_type' => 'tenant.activated',

        ]);

    }


    public function test_super_admin_can_view_tenant_activity_and_status_history(): void

    {

        $business = Tenant::create([

            'name' => 'Audit Test Business',

            'slug' => 'audit-test-business',

            'status' => 'active',

        ]);


        $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/suspend", [

                'suspend_reason' => 'Payment overdue',

            ])

            ->assertOk();


        $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/archive")

            ->assertOk();


        $activityResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}/activity");


        $activityResponse

            ->assertOk()

            ->assertJsonCount(2, 'data')

            ->assertJsonFragment([

                'event_type' => 'tenant.suspended',

            ])

            ->assertJsonFragment([

                'event_type' => 'tenant.archived',

            ]);


        $historyResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}/status-history");


        $historyResponse

            ->assertOk()

            ->assertJsonCount(2, 'data')

            ->assertJsonFragment([

                'previous_status' => 'active',

                'new_status' => 'suspended',

            ])

            ->assertJsonFragment([

                'previous_status' => 'suspended',

                'new_status' => 'archived',

            ]);

    }


    public function test_super_admin_can_create_list_update_and_pin_tenant_internal_notes(): void

    {

        $business = Tenant::create([

            'name' => 'Notes Test Business',

            'slug' => 'notes-test-business',

            'status' => 'active',

        ]);


        // Create note

        $createResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/notes", [

                'note_type' => 'support',

                'note' => 'Customer requested onboarding assistance.',

            ]);


        $createResponse

            ->assertCreated()

            ->assertJsonPath('data.tenant_id', $business->id)

            ->assertJsonPath('data.author_user_id', $this->superAdmin->id)

            ->assertJsonPath('data.note_type', 'support')

            ->assertJsonPath('data.is_pinned', false);


        $noteId = $createResponse->json('data.id');


        $this->assertDatabaseHas('tenant_internal_notes', [

            'id' => $noteId,

            'tenant_id' => $business->id,

            'author_user_id' => $this->superAdmin->id,

            'note' => 'Customer requested onboarding assistance.',

        ]);


        // List notes

        $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}/notes")

            ->assertOk()

            ->assertJsonCount(1, 'data');


        // Update note

        $this->actingAs($this->superAdmin, 'sanctum')

            ->patchJson("/api/auth/admin/tenants/{$business->id}/notes/{$noteId}", [

                'note' => 'Onboarding assistance completed.',

                'note_type' => 'general',

            ])

            ->assertOk()

            ->assertJsonPath('data.note', 'Onboarding assistance completed.')

            ->assertJsonPath('data.note_type', 'general');


        // Pin note

        $this->actingAs($this->superAdmin, 'sanctum')

            ->patchJson("/api/auth/admin/tenants/{$business->id}/notes/{$noteId}/pin")

            ->assertOk()

            ->assertJsonPath('data.is_pinned', true);


        $this->assertDatabaseHas('tenant_internal_notes', [

            'id' => $noteId,

            'is_pinned' => true,

        ]);

    }


    public function test_tenant_internal_note_can_be_soft_deleted(): void

    {

        $business = Tenant::create([

            'name' => 'Notes Delete Business',

            'slug' => 'notes-delete-business',

            'status' => 'active',

        ]);


        $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/notes", [

                'note' => 'This note will be deleted.',

            ])

            ->assertCreated();


        $noteId = \App\Models\TenantInternalNote::query()

            ->where('tenant_id', $business->id)

            ->value('id');

        $this->assertNotNull($noteId, 'Created tenant note should exist before deletion.');

        $this->actingAs($this->superAdmin, 'sanctum')

            ->deleteJson("/api/auth/admin/tenants/{$business->id}/notes/{$noteId}")

            ->assertOk();


        $this->assertSoftDeleted('tenant_internal_notes', [

            'id' => $noteId,

        ]);


        $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}/notes")

            ->assertOk()

            ->assertJsonCount(0, 'data');

    }


    public function test_tenant_internal_note_cannot_be_accessed_through_another_tenant(): void

    {

        $businessA = Tenant::create([

            'name' => 'Notes Tenant A',

            'slug' => 'notes-tenant-a',

            'status' => 'active',

        ]);


        $businessB = Tenant::create([

            'name' => 'Notes Tenant B',

            'slug' => 'notes-tenant-b',

            'status' => 'active',

        ]);


        $createResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$businessA->id}/notes", [

                'note' => 'Private note for Tenant A.',

            ])

            ->assertCreated();


        $noteId = $createResponse->json('data.id');


        // Tenant B must not be able to use Tenant A's note.

        $this->actingAs($this->superAdmin, 'sanctum')

            ->patchJson("/api/auth/admin/tenants/{$businessB->id}/notes/{$noteId}", [

                'note' => 'Attempted cross-tenant modification.',

            ])

            ->assertNotFound();


        $this->actingAs($this->superAdmin, 'sanctum')

            ->deleteJson("/api/auth/admin/tenants/{$businessB->id}/notes/{$noteId}")

            ->assertNotFound();


        $this->assertDatabaseHas('tenant_internal_notes', [

            'id' => $noteId,

            'tenant_id' => $businessA->id,

            'note' => 'Private note for Tenant A.',

        ]);

    }


    public function test_super_admin_can_create_and_queue_tenant_bulk_operation(): void

    {

        Queue::fake();


        $tenantA = Tenant::create([

            'name' => 'Bulk Tenant A',

            'slug' => 'bulk-tenant-a',

            'status' => 'inactive',

        ]);


        $tenantB = Tenant::create([

            'name' => 'Bulk Tenant B',

            'slug' => 'bulk-tenant-b',

            'status' => 'inactive',

        ]);


        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson('/api/auth/admin/tenant-bulk-operations', [

                'operation_type' => 'activate',

                'tenant_ids' => [$tenantA->id, $tenantB->id],

            ]);


        $response->assertStatus(202)

            ->assertJsonPath('data.operation_type', 'activate')

            ->assertJsonPath('data.total_items', 2);


        $operationId = $response->json('data.id');


        $this->assertDatabaseHas('tenant_bulk_operations', [

            'id' => $operationId,

            'initiated_by' => $this->superAdmin->id,

            'status' => 'pending',

            'total_items' => 2,

        ]);


        $this->assertDatabaseHas('tenant_bulk_operation_items', [

            'bulk_operation_id' => $operationId,

            'tenant_id' => $tenantA->id,

            'status' => 'pending',

        ]);


        $this->assertDatabaseHas('tenant_bulk_operation_items', [

            'bulk_operation_id' => $operationId,

            'tenant_id' => $tenantB->id,

            'status' => 'pending',

        ]);


        Queue::assertPushed(

            ProcessTenantBulkOperationJob::class,

            fn($job) => $job->operationId === $operationId

        );

    }


    public function test_bulk_operation_rejects_duplicate_tenant_ids(): void

    {

        Queue::fake();


        $tenant = Tenant::create([

            'name' => 'Duplicate Bulk Tenant',

            'slug' => 'duplicate-bulk-tenant',

            'status' => 'active',

        ]);


        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson('/api/auth/admin/tenant-bulk-operations', [

                'operation_type' => 'archive',

                'tenant_ids' => [$tenant->id, $tenant->id],

            ]);


        $response->assertUnprocessable();


        $this->assertDatabaseCount('tenant_bulk_operations', 0);


        Queue::assertNothingPushed();

    }


    public function test_super_admin_can_retry_failed_bulk_operation_items(): void

    {

        Queue::fake();


        $tenant = Tenant::create([

            'name' => 'Retry Bulk Tenant',

            'slug' => 'retry-bulk-tenant',

            'status' => 'active',

        ]);


        $operation = TenantBulkOperation::create([

            'operation_type' => 'archive',

            'status' => 'partial_failed',

            'initiated_by' => $this->superAdmin->id,

            'total_items' => 1,

            'processed_items' => 1,

            'successful_items' => 0,

            'failed_items' => 1,

        ]);


        $item = $operation->items()->create([

            'tenant_id' => $tenant->id,

            'item_type' => 'tenant',

            'item_id' => $tenant->id,

            'status' => 'failed',

            'idempotency_key' => "retry-tenant:{$tenant->id}",

            'message' => 'Temporary processing error.',

            'result' => ['error' => 'Temporary processing error.'],

            'processed_at' => now(),

        ]);


        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson(

                "/api/auth/admin/tenant-bulk-operations/{$operation->id}/retry"

            );


        $response->assertStatus(202)

            ->assertJsonPath('retry_items', 1);


        $this->assertDatabaseHas('tenant_bulk_operation_items', [

            'id' => $item->id,

            'status' => 'pending',

            'message' => null,

        ]);


        $this->assertDatabaseHas('tenant_bulk_operations', [

            'id' => $operation->id,

            'status' => 'pending',

            'failed_items' => 0,

        ]);


        Queue::assertPushed(

            ProcessTenantBulkOperationJob::class,

            fn($job) => $job->operationId === $operation->id

        );

    }


    public function test_safe_delete_prevents_deleting_business_with_dependent_accounts_or_members(): void

    {

        $business = Tenant::create(['company_name' => 'Enterprise Inc', 'slug' => 'enterprise-inc', 'status' => 'active']);

        TenantUser::create([

            'tenant_id' => $business->id,

            'user_id' => $this->owner->id,

            'role' => 'owner',

            'status' => 'active',

        ]);


        // Attempt delete while members exist

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->deleteJson("/api/auth/admin/tenants/{$business->id}");


        $response->assertStatus(409);

        $this->assertDatabaseHas('tenants', ['id' => $business->id]);


        // Remove members

        TenantUser::where('tenant_id', $business->id)->delete();


        // Now safe delete succeeds

        $response2 = $this->actingAs($this->superAdmin, 'sanctum')

            ->deleteJson("/api/auth/admin/tenants/{$business->id}");


        $response2->assertStatus(200);

        $this->assertDatabaseMissing('tenants', ['id' => $business->id]);

    }


    public function test_view_and_update_business_profile(): void

    {

        $business = Tenant::create([

            'company_name' => 'Profile Business',

            'slug' => 'profile-business',

            'status' => 'active',

            'email' => 'info@profilebusiness.com',

            'contact_number' => '+919876543210',

            'country' => 'India',

        ]);

        TenantUser::create([

            'tenant_id' => $business->id,

            'user_id' => $this->owner->id,

            'role' => 'owner',

            'status' => 'active',

        ]);


        // View tenant/business details (contains full business profile)

        $response = $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}");


        $response->assertStatus(200);

        $response->assertJsonPath('data.company_name', 'Profile Business');

        $response->assertJsonPath('data.email', 'info@profilebusiness.com');

        $response->assertJsonPath('data.contact_number', '+919876543210');

        $response->assertJsonPath('data.country', 'India');


        // Update tenant/business directly with profile fields in the same API

        $updateResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->putJson("/api/auth/admin/tenants/{$business->id}", [

                'company_name' => 'Updated Profile Business',

                'country' => 'United States',

                'currency' => 'USD',

                'address' => '123 Market St',

            ]);


        $updateResponse->assertStatus(200);

        $this->assertEquals('Updated Profile Business', $business->fresh()->company_name);

        $this->assertEquals('United States', $business->fresh()->country);

        $this->assertEquals('USD', $business->fresh()->currency);

        $this->assertEquals('123 Market St', $business->fresh()->address);

    }


    public function test_business_members_management(): void

    {

        $business = Tenant::create(['name' => 'Team Business', 'slug' => 'team-business', 'status' => 'active']);


        // Add member

        $addResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->postJson("/api/auth/admin/tenants/{$business->id}/members", [

                'user_id' => $this->otherUser->id,

                'role' => 'agent',

                'status' => 'active',

            ]);


        $addResponse->assertStatus(201);

        $memberId = $addResponse->json('data.id');


        // List members

        $listResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->getJson("/api/auth/admin/tenants/{$business->id}/members");

        $listResponse->assertStatus(200);

        $listResponse->assertJsonCount(1, 'data');


        // Update member

        $updateResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->patchJson("/api/auth/admin/tenants/{$business->id}/members/{$memberId}", [

                'role' => 'manager',

            ]);

        $updateResponse->assertStatus(200);


        // Delete member

        $delResponse = $this->actingAs($this->superAdmin, 'sanctum')

            ->deleteJson("/api/auth/admin/tenants/{$business->id}/members/{$memberId}");

        $delResponse->assertStatus(200);

        $this->assertDatabaseMissing('tenant_users', ['id' => $memberId]);

    }


    public function test_tenant_authorization_isolation(): void

    {

        $business = Tenant::create(['name' => 'Isolated Business', 'slug' => 'isolated-biz', 'status' => 'active']);

        TenantUser::create([

            'tenant_id' => $business->id,

            'user_id' => $this->owner->id,

            'role' => 'owner',

            'status' => 'active',

        ]);


        // otherUser who is not a member cannot view this tenant

        $this->actingAs($this->otherUser, 'sanctum');

        $this->otherUser->assignRole('agent'); // Non super-admin role


        $response = $this->getJson("/api/auth/admin/tenants/{$business->id}");

        $response->assertStatus(403);

    }

}
