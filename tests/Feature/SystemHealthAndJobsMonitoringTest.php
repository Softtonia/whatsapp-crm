<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SystemHealthAndJobsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $monitorAdmin;
    protected User $unauthorizedAdmin;
    protected Role $superAdminRole;
    protected Role $monitorRole;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Roles & Permissions
        $this->superAdminRole = Role::findOrCreate('Super Admin', 'web');
        $this->superAdminRole->slug = 'super_admin';
        $this->superAdminRole->save();

        $this->monitorRole = Role::findOrCreate('System Monitor', 'web');
        $this->monitorRole->slug = 'system_monitor';
        $this->monitorRole->save();

        $readPerm = Permission::findOrCreate('monitoring.read', 'web');
        $retryPerm = Permission::findOrCreate('monitoring.retry', 'web');

        $this->superAdminRole->givePermissionTo([$readPerm, $retryPerm]);
        $this->monitorRole->givePermissionTo([$readPerm]); // Only read, cannot retry

        // 2. Users
        $this->superAdmin = User::factory()->create(['status' => 'active', 'email' => 'sa@crm.test']);
        $this->superAdmin->assignRole($this->superAdminRole);

        $this->monitorAdmin = User::factory()->create(['status' => 'active', 'email' => 'monitor@crm.test']);
        $this->monitorAdmin->assignRole($this->monitorRole);

        $this->unauthorizedAdmin = User::factory()->create(['status' => 'active', 'email' => 'unauth@crm.test']);
        $noPermRole = Role::findOrCreate('Viewer', 'web');
        $this->unauthorizedAdmin->assignRole($noPermRole);
    }

    /**
     * Helper to create a failed job entry.
     */
    protected function createFailedJob(string $queue = 'default', string $commandClass = 'App\\Jobs\\ProcessWhatsAppWebhookJob'): int
    {
        $payload = json_encode([
            'displayName' => $commandClass,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'timeout' => 60,
            'data' => [
                'commandName' => $commandClass,
                'command' => 'O:34:"' . $commandClass . '":1:{s:9:"tenant_id";i:1;}',
            ],
            'attempts' => 3,
        ]);

        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => $payload,
            'exception' => "RuntimeException: Call to external API failed with error secret=XYZ12345 in D:\\app\\Job.php:42",
            'failed_at' => now(),
        ]);
    }

    /**
     * OPS-001 — Redis unavailable:
     * Simulate Redis connectivity failure and verify health reports Redis as unavailable without leaking secrets.
     */
    public function test_ops_001_redis_unavailable_reported_properly(): void
    {
        // Mock Redis::connection()->ping() to throw an exception
        Redis::shouldReceive('connection->ping')
            ->andThrow(new \Exception('Connection refused [tcp://127.0.0.1:6379] password=SecretRedisPassword'));

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/monitoring/health');

        // Health should return either 200 (if non-critical degraded) or 503, reporting Redis unavailable
        $redisComponent = $response->json('data.components.redis');
        $this->assertEquals('unavailable', $redisComponent['status']);
        $this->assertStringContainsString('Redis connection failed', $redisComponent['message']);

        // Assert secret redaction
        $this->assertStringNotContainsString('SecretRedisPassword', $redisComponent['message']);
        $this->assertStringContainsString('[redacted]', $redisComponent['message']);
    }

    /**
     * OPS-002 — Database unavailable:
     * Simulate database connectivity failure and verify health result accurately reports database failure.
     */
    public function test_ops_002_database_unavailable_reported_safely(): void
    {
        // Mock DB::connection()->select to throw an exception
        DB::shouldReceive('connection->select')
            ->andThrow(new \Exception('SQLSTATE[HY000] [2002] Connection refused to host=127.0.0.1 password=SecretDbPassword'));

        $healthService = app(SystemHealthService::class);
        $dbCheck = $healthService->checkDatabase();

        $this->assertEquals('unavailable', $dbCheck['status']);
        $this->assertStringContainsString('Database connection failed', $dbCheck['message']);

        // Assert secrets are redacted
        $this->assertStringNotContainsString('SecretDbPassword', $dbCheck['message']);
        $this->assertStringContainsString('[redacted]', $dbCheck['message']);

        // Readiness probe must return 503 not_ready when DB is unavailable
        $readiness = $healthService->checkReadiness();
        $this->assertEquals('not_ready', $readiness['status']);
        $this->assertEquals('unavailable', $readiness['database']);
    }

    /**
     * OPS-003 — Queue failure:
     * Simulate queue connection/failed job buildup and verify monitoring accurately reports it.
     */
    public function test_ops_003_queue_failure_and_degraded_status_reported(): void
    {
        // Insert 15 failed jobs
        for ($i = 0; $i < 15; $i++) {
            $this->createFailedJob('notifications');
        }

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/auth/admin/monitoring/health');

        $response->assertStatus(200);
        $queueComponent = $response->json('data.components.queue');
        $this->assertEquals('degraded', $queueComponent['status']);
        $this->assertGreaterThanOrEqual(15, $queueComponent['failed_jobs']);
        $this->assertStringContainsString('failed jobs requiring attention', $queueComponent['message']);
    }

    /**
     * OPS-004 — Retry failed job:
     * Create eligible failed job, retry it through authorized API, verify correct job is retried and result recorded.
     */
    public function test_ops_004_retry_failed_job_succeeds_for_authorized_admin(): void
    {
        $jobId = $this->createFailedJob('webhooks');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'retried')
            ->assertJsonPath('data.id', (string) $jobId);

        // Verify activity log recorded
        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'queue.job.retry',
            'category' => 'monitoring',
        ]);

        // Job details should now show state as 'retried'
        $details = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/auth/admin/monitoring/jobs/{$jobId}");

        $details->assertStatus(200)
            ->assertJsonPath('data.state', 'retried')
            ->assertJsonPath('data.is_retryable', false);
    }

    /**
     * OPS-005 — Repeated retry:
     * Submit repeated retry requests for same failed job. Concurrency/idempotency guard prevents duplicate dispatches.
     */
    public function test_ops_005_repeated_retry_prevented_and_returns_conflict(): void
    {
        $jobId = $this->createFailedJob('webhooks');

        // First retry succeeds
        $first = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");
        $first->assertStatus(200);

        // Second retry must be rejected with 409 already_retried
        $second = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");

        $second->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'already_retried');
    }

    /**
     * OPS-006 — Unauthorized retry:
     * Verify unauthenticated rejected (401), missing monitoring.retry rejected (403), authorized succeeds (200).
     */
    public function test_ops_006_unauthorized_retry_rejected_and_authorized_allowed(): void
    {
        $jobId = $this->createFailedJob('campaigns');

        // 1. Unauthenticated -> 401
        $unauth = $this->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");
        $unauth->assertStatus(401);

        // 2. Authenticated user without monitoring.retry (only has monitoring.read) -> 403
        $forbidden = $this->actingAs($this->monitorAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");
        $forbidden->assertStatus(403);

        // 3. User with no monitoring permissions at all -> 403
        $unauthAdmin = $this->actingAs($this->unauthorizedAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");
        $unauthAdmin->assertStatus(403);

        // 4. Authorized Super Admin -> 200
        $authorized = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/auth/admin/monitoring/jobs/{$jobId}/retry");
        $authorized->assertStatus(200);
    }

    /**
     * Test Liveness and Readiness probes.
     */
    public function test_liveness_and_readiness_probes(): void
    {
        // Public probes do not require authentication
        $liveness = $this->getJson('/api/monitoring/liveness');
        $liveness->assertStatus(200)
            ->assertJsonPath('status', 'healthy');

        $readiness = $this->getJson('/api/monitoring/readiness');
        $readiness->assertStatus(200)
            ->assertJsonPath('status', 'ready');
    }

    /**
     * Test Background Jobs List API pagination and filters.
     */
    public function test_jobs_list_pagination_and_filters(): void
    {
        $this->createFailedJob('default', 'App\\Jobs\\DispatchCampaignJob');
        $this->createFailedJob('webhooks', 'App\\Jobs\\ProcessWhatsAppWebhookJob');

        $response = $this->actingAs($this->monitorAdmin, 'sanctum')
            ->getJson('/api/auth/admin/monitoring/jobs?per_page=10&page=1&queue=webhooks');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'job_type',
                        'queue',
                        'state',
                        'attempts',
                        'failed_at',
                        'last_error',
                    ],
                ],
                'meta' => ['current_page', 'total', 'per_page'],
            ]);

        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertEquals('webhooks', $items[0]['queue']);
    }

    /**
     * Test Job Details API with 404 for unknown job ID.
     */
    public function test_show_job_returns_404_for_unknown_job(): void
    {
        $response = $this->actingAs($this->monitorAdmin, 'sanctum')
            ->getJson('/api/auth/admin/monitoring/jobs/999999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'not_found');
    }
}
