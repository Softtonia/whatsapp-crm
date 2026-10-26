<?php

namespace Tests\Feature;

use App\Jobs\ProcessTenantOffboardingJob;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantOffboardingJob as OffboardingRequest;
use App\Models\TenantStatusHistory;
use App\Models\TenantOffboardingStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantOffboardingWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');

        $this->superAdmin = User::factory()->create([
            'email' => 'offboarding-worker-admin@example.com',
        ]);

        $this->superAdmin->assignRole('Super Admin');
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Test Tenant ' . $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function createOffboardingRequest(
        Tenant $tenant,
        string $status = 'approved',
        array $metadata = []
    ): OffboardingRequest {
        return OffboardingRequest::create([
            'tenant_id' => $tenant->id,
            'requested_by' => $this->superAdmin->id,
            'approved_by' => in_array(
                $status,
                ['approved', 'processing', 'completed'],
                true
            ) ? $this->superAdmin->id : null,
            'status' => $status,
            'reason' => 'Tenant offboarding worker test.',
            'metadata' => $metadata,
        ]);
    }

    public function test_approved_request_archives_tenant_and_completes_workflow(): void
    {
        $tenant = $this->createTenant('worker-approved-tenant');
        $request = $this->createOffboardingRequest($tenant);

        (new ProcessTenantOffboardingJob($request->id))->handle();

        $tenant->refresh();
        $request->refresh();

        $this->assertSame('archived', $tenant->status);
        $this->assertSame('completed', $request->status);
        $this->assertNotNull($request->completed_at);

        $this->assertDatabaseHas('tenant_offboarding_steps', [
            'offboarding_job_id' => $request->id,
            'step_key' => 'archive_tenant',
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('tenant_status_history', [
            'tenant_id' => $tenant->id,
            'previous_status' => 'active',
            'new_status' => 'archived',
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant.offboarding_archived',
        ]);
    }

    public function test_unapproved_requests_are_not_processed(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $tenant = $this->createTenant('worker-' . $status . '-tenant');

            $request = $this->createOffboardingRequest(
                $tenant,
                $status
            );

            (new ProcessTenantOffboardingJob($request->id))->handle();

            $tenant->refresh();
            $request->refresh();

            $this->assertSame(
                'active',
                $tenant->status,
                "Tenant must remain active for a {$status} request."
            );

            $this->assertSame($status, $request->status);

            $this->assertDatabaseMissing('tenant_offboarding_steps', [
                'offboarding_job_id' => $request->id,
                'step_key' => 'archive_tenant',
            ]);
        }
    }

    public function test_repeated_execution_does_not_duplicate_audit_records(): void
    {
        $tenant = $this->createTenant('worker-idempotent-tenant');
        $request = $this->createOffboardingRequest($tenant);

        $worker = new ProcessTenantOffboardingJob($request->id);

        $worker->handle();
        $worker->handle();

        $this->assertSame(
            1,
            TenantStatusHistory::query()
                ->where('tenant_id', $tenant->id)
                ->where('new_status', 'archived')
                ->count()
        );

        $this->assertSame(
            1,
            TenantActivityLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('event_type', 'tenant.offboarding_archived')
                ->count()
        );

        $this->assertSame(
            1,
            TenantOffboardingStep::query()
                ->where('offboarding_job_id', $request->id)
                ->where('step_key', 'archive_tenant')
                ->count()
        );

        $this->assertSame('completed', $request->fresh()->status);
    }

    public function test_explicit_retry_can_process_a_failed_request(): void
    {
        $tenant = $this->createTenant('worker-retry-tenant');

        $request = $this->createOffboardingRequest(
            $tenant,
            'pending',
            ['retry_execution_pending_worker' => true]
        );

        $request->steps()->create([
            'step_key' => 'archive_tenant',
            'status' => 'failed',
            'attempts' => 1,
            'error_message' => 'Previous attempt failed.',
        ]);

        (new ProcessTenantOffboardingJob($request->id))->handle();

        $this->assertSame('archived', $tenant->fresh()->status);
        $this->assertSame('completed', $request->fresh()->status);

        $this->assertDatabaseHas('tenant_offboarding_steps', [
            'offboarding_job_id' => $request->id,
            'step_key' => 'archive_tenant',
            'status' => 'completed',
        ]);
    }

    public function test_failed_callback_marks_processing_request_as_failed(): void
    {
        $tenant = $this->createTenant('worker-failure-tenant');

        $request = $this->createOffboardingRequest(
            $tenant,
            'processing'
        );

        $exception = new RuntimeException(
            'Simulated offboarding failure.'
        );

        $worker = new ProcessTenantOffboardingJob($request->id);

        $worker->failed($exception);

        $request->refresh();

        $this->assertSame('failed', $request->status);

        $this->assertSame(
            'Simulated offboarding failure.',
            $request->metadata['last_error']
        );

        $this->assertDatabaseHas('tenant_offboarding_steps', [
            'offboarding_job_id' => $request->id,
            'step_key' => 'archive_tenant',
            'status' => 'failed',
        ]);

        $this->assertSame('active', $tenant->fresh()->status);
    }
}
