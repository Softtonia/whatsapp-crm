<?php

namespace Tests\Feature;

use App\Jobs\ProcessTenantOffboardingJob;
use App\Models\Tenant;
use App\Models\TenantOffboardingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DispatchScheduledTenantOffboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');

        $this->admin = User::factory()->create([
            'email' => 'scheduled-offboarding-admin@example.com',
        ]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Test Tenant ' . $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function createJob(
        Tenant $tenant,
        array $overrides = []
    ): TenantOffboardingJob {
        return TenantOffboardingJob::create(array_merge([
            'tenant_id' => $tenant->id,
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'status' => 'approved',
            'reason' => 'Scheduled offboarding test.',
            'scheduled_for' => now()->subMinute(),
            'purge_status' => 'not_requested',
        ], $overrides));
    }

    public function test_due_normal_offboarding_job_is_dispatched(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('dispatch-normal');
        $job = $this->createJob($tenant);

        $this->artisan('tenants:dispatch-scheduled-offboarding')
            ->assertExitCode(0);

        Queue::assertPushed(
            ProcessTenantOffboardingJob::class,
            fn ($queued) => $queued->offboardingJobId === $job->id
        );
    }

    public function test_purge_request_is_not_dispatched(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('dispatch-purge');
        $job = $this->createJob($tenant, [
            'options' => ['operation' => 'purge_request'],
            'purge_status' => 'requested',
        ]);

        $this->artisan('tenants:dispatch-scheduled-offboarding')
            ->assertExitCode(0);

        Queue::assertNotPushed(
            ProcessTenantOffboardingJob::class,
            fn ($queued) => $queued->offboardingJobId === $job->id
        );
    }

    public function test_future_scheduled_job_is_not_dispatched(): void
    {
        Queue::fake();

        $tenant = $this->createTenant('dispatch-future');
        $job = $this->createJob($tenant, [
            'scheduled_for' => now()->addDay(),
        ]);

        $this->artisan('tenants:dispatch-scheduled-offboarding')
            ->assertExitCode(0);

        Queue::assertNotPushed(
            ProcessTenantOffboardingJob::class,
            fn ($queued) => $queued->offboardingJobId === $job->id
        );
    }
}
