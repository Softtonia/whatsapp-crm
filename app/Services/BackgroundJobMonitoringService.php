<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class BackgroundJobMonitoringService
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * Get paginated background jobs across failed and pending storage.
     */
    public function getJobs(array $filters = [], int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $statusFilter = $filters['status'] ?? null;
        $queueFilter = $filters['queue'] ?? null;
        $searchFilter = $filters['search'] ?? null;
        $jobClassFilter = $filters['job_class'] ?? null;
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;

        $jobsList = [];

        // 1. Fetch from failed_jobs
        if (Schema::hasTable('failed_jobs') && ($statusFilter === null || $statusFilter === 'failed')) {
            $failedQuery = DB::table('failed_jobs')->orderByDesc('failed_at');

            if ($queueFilter) {
                $failedQuery->where('queue', $queueFilter);
            }
            if ($dateFrom) {
                $failedQuery->where('failed_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $failedQuery->where('failed_at', '<=', $dateTo);
            }

            $failedRecords = $failedQuery->limit(200)->get();

            foreach ($failedRecords as $fJob) {
                $parsed = $this->parsePayload($fJob->payload);
                $jobDisplayName = $parsed['display_name'];
                $tenantId = $parsed['tenant_id'];

                if ($jobClassFilter && ! str_contains(strtolower($jobDisplayName), strtolower($jobClassFilter))) {
                    continue;
                }
                if ($searchFilter && ! str_contains(strtolower($jobDisplayName . ' ' . $fJob->queue . ' ' . ($parsed['command_name'] ?? '')), strtolower($searchFilter))) {
                    continue;
                }

                $retriedAt = Cache::get("job_retried_at_{$fJob->id}");
                $currentState = $retriedAt ? 'retried' : 'failed';

                if ($statusFilter && $statusFilter !== $currentState && $statusFilter !== 'failed') {
                    continue;
                }

                $jobsList[] = [
                    'id' => (string) $fJob->id,
                    'uuid' => $fJob->uuid ?? (string) $fJob->id,
                    'job_type' => $jobDisplayName,
                    'command_name' => $parsed['command_name'],
                    'tenant_id' => $tenantId,
                    'tenant_name' => $this->resolveTenantName($tenantId),
                    'queue' => $fJob->queue,
                    'connection' => $fJob->connection,
                    'state' => $currentState,
                    'attempts' => $parsed['attempts'] ?? 1,
                    'created_at' => $parsed['created_at'] ?? $fJob->failed_at,
                    'failed_at' => $fJob->failed_at,
                    'retried_at' => $retriedAt,
                    'last_error' => $this->sanitizeErrorSummary($fJob->exception),
                ];
            }
        }

        // 2. Fetch pending jobs from jobs table (if using database queue)
        if (Schema::hasTable('jobs') && config('queue.default') === 'database' && ($statusFilter === null || in_array($statusFilter, ['pending', 'running'], true))) {
            $pendingQuery = DB::table('jobs')->orderByDesc('created_at');

            if ($queueFilter) {
                $pendingQuery->where('queue', $queueFilter);
            }

            $pendingRecords = $pendingQuery->limit(100)->get();

            foreach ($pendingRecords as $pJob) {
                $parsed = $this->parsePayload($pJob->payload);
                $jobDisplayName = $parsed['display_name'];
                $tenantId = $parsed['tenant_id'];

                if ($jobClassFilter && ! str_contains(strtolower($jobDisplayName), strtolower($jobClassFilter))) {
                    continue;
                }
                if ($searchFilter && ! str_contains(strtolower($jobDisplayName . ' ' . $pJob->queue), strtolower($searchFilter))) {
                    continue;
                }

                $state = $pJob->reserved_at !== null ? 'running' : 'pending';
                if ($statusFilter && $statusFilter !== $state) {
                    continue;
                }

                $createdAt = Carbon::createFromTimestamp($pJob->created_at)->toIso8601String();
                $availableAt = Carbon::createFromTimestamp($pJob->available_at)->toIso8601String();
                $reservedAt = $pJob->reserved_at ? Carbon::createFromTimestamp($pJob->reserved_at)->toIso8601String() : null;

                $jobsList[] = [
                    'id' => (string) $pJob->id,
                    'uuid' => (string) $pJob->id,
                    'job_type' => $jobDisplayName,
                    'command_name' => $parsed['command_name'],
                    'tenant_id' => $tenantId,
                    'tenant_name' => $this->resolveTenantName($tenantId),
                    'queue' => $pJob->queue,
                    'connection' => 'database',
                    'state' => $state,
                    'attempts' => (int) $pJob->attempts,
                    'created_at' => $createdAt,
                    'available_at' => $availableAt,
                    'reserved_at' => $reservedAt,
                    'failed_at' => null,
                    'retried_at' => null,
                    'last_error' => null,
                ];
            }
        }

        // Sort descending by created_at / failed_at
        usort($jobsList, function ($a, $b) {
            $tA = $a['failed_at'] ?? $a['created_at'] ?? '';
            $tB = $b['failed_at'] ?? $b['created_at'] ?? '';
            return strcmp($tB, $tA);
        });

        $total = count($jobsList);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($jobsList, $offset, $perPage);

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Find job details by ID or UUID.
     */
    public function getJobDetails(string $id): ?array
    {
        // 1. Search in failed_jobs first
        if (Schema::hasTable('failed_jobs')) {
            $failedJob = DB::table('failed_jobs')
                ->where('id', $id)
                ->orWhere('uuid', $id)
                ->first();

            if ($failedJob) {
                $parsed = $this->parsePayload($failedJob->payload);
                $retriedAt = Cache::get("job_retried_at_{$failedJob->id}");

                return [
                    'id' => (string) $failedJob->id,
                    'uuid' => $failedJob->uuid ?? (string) $failedJob->id,
                    'job_type' => $parsed['display_name'],
                    'command_name' => $parsed['command_name'],
                    'tenant_id' => $parsed['tenant_id'],
                    'tenant_name' => $this->resolveTenantName($parsed['tenant_id']),
                    'queue' => $failedJob->queue,
                    'connection' => $failedJob->connection,
                    'state' => $retriedAt ? 'retried' : 'failed',
                    'attempts' => $parsed['attempts'] ?? 1,
                    'created_at' => $parsed['created_at'] ?? $failedJob->failed_at,
                    'failed_at' => $failedJob->failed_at,
                    'retried_at' => $retriedAt,
                    'is_retryable' => $retriedAt === null,
                    'error_summary' => $this->sanitizeErrorSummary($failedJob->exception),
                    'error_details' => $this->sanitizeFullException($failedJob->exception),
                    'metadata' => [
                        'max_tries' => $parsed['max_tries'] ?? null,
                        'timeout' => $parsed['timeout'] ?? null,
                    ],
                ];
            }
        }

        // 2. Check retried jobs cached snapshot (since Laravel's queue:retry deletes the failed_jobs row upon requeue)
        $cachedSnapshot = Cache::get("job_retried_snapshot_{$id}");
        if ($cachedSnapshot) {
            return $cachedSnapshot;
        }

        // 3. Search in active/pending jobs table
        if (Schema::hasTable('jobs') && config('queue.default') === 'database') {
            $activeJob = DB::table('jobs')->where('id', $id)->first();

            if ($activeJob) {
                $parsed = $this->parsePayload($activeJob->payload);
                $state = $activeJob->reserved_at !== null ? 'running' : 'pending';

                return [
                    'id' => (string) $activeJob->id,
                    'uuid' => (string) $activeJob->id,
                    'job_type' => $parsed['display_name'],
                    'command_name' => $parsed['command_name'],
                    'tenant_id' => $parsed['tenant_id'],
                    'tenant_name' => $this->resolveTenantName($parsed['tenant_id']),
                    'queue' => $activeJob->queue,
                    'connection' => 'database',
                    'state' => $state,
                    'attempts' => (int) $activeJob->attempts,
                    'created_at' => Carbon::createFromTimestamp($activeJob->created_at)->toIso8601String(),
                    'available_at' => Carbon::createFromTimestamp($activeJob->available_at)->toIso8601String(),
                    'reserved_at' => $activeJob->reserved_at ? Carbon::createFromTimestamp($activeJob->reserved_at)->toIso8601String() : null,
                    'failed_at' => null,
                    'retried_at' => null,
                    'is_retryable' => false,
                    'error_summary' => null,
                    'error_details' => null,
                    'metadata' => [
                        'max_tries' => $parsed['max_tries'] ?? null,
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Retry a failed job with concurrency lock and idempotency.
     */
    public function retryJob(string $id, ?int $actorId = null): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'success' => false,
                'status' => 'unavailable',
                'message' => 'Failed jobs table does not exist.',
                'code' => 404,
            ];
        }

        $lockKey = "retry_lock_job_{$id}";
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return [
                'success' => false,
                'status' => 'conflict',
                'message' => 'This job is currently being retried by another request.',
                'code' => 409,
            ];
        }

        try {
            // Check if already retried before querying failed_jobs table
            // (Laravel's queue:retry deletes the record from failed_jobs upon successful requeue)
            if (Cache::has("job_retried_at_{$id}")) {
                return [
                    'success' => false,
                    'status' => 'already_retried',
                    'message' => "Job {$id} has already been retried.",
                    'code' => 409,
                ];
            }

            $failedJob = DB::table('failed_jobs')
                ->where('id', $id)
                ->orWhere('uuid', $id)
                ->first();

            if (! $failedJob) {
                return [
                    'success' => false,
                    'status' => 'not_found',
                    'message' => "Failed job with ID {$id} not found.",
                    'code' => 404,
                ];
            }

            // Check if already retried by internal id/uuid
            if (Cache::has("job_retried_at_{$failedJob->id}") || (! empty($failedJob->uuid) && Cache::has("job_retried_at_{$failedJob->uuid}"))) {
                return [
                    'success' => false,
                    'status' => 'already_retried',
                    'message' => "Job {$id} has already been retried.",
                    'code' => 409,
                ];
            }

            // Build snapshot to preserve details after Laravel deletes it from failed_jobs
            $parsed = $this->parsePayload($failedJob->payload);
            $retriedAt = now()->toIso8601String();
            $snapshot = [
                'id' => (string) $failedJob->id,
                'uuid' => $failedJob->uuid ?? (string) $failedJob->id,
                'job_type' => $parsed['display_name'],
                'command_name' => $parsed['command_name'],
                'tenant_id' => $parsed['tenant_id'],
                'tenant_name' => $this->resolveTenantName($parsed['tenant_id']),
                'queue' => $failedJob->queue,
                'connection' => $failedJob->connection,
                'state' => 'retried',
                'attempts' => $parsed['attempts'] ?? 1,
                'created_at' => $parsed['created_at'] ?? $failedJob->failed_at,
                'failed_at' => $failedJob->failed_at,
                'retried_at' => $retriedAt,
                'is_retryable' => false,
                'error_summary' => $this->sanitizeErrorSummary($failedJob->exception),
                'error_details' => $this->sanitizeFullException($failedJob->exception),
                'metadata' => [
                    'max_tries' => $parsed['max_tries'] ?? null,
                    'timeout' => $parsed['timeout'] ?? null,
                ],
            ];

            // Cache snapshot under both ID and UUID
            Cache::put("job_retried_snapshot_{$failedJob->id}", $snapshot, now()->addDays(7));
            if (! empty($failedJob->uuid)) {
                Cache::put("job_retried_snapshot_{$failedJob->uuid}", $snapshot, now()->addDays(7));
            }
            Cache::put("job_retried_at_{$failedJob->id}", $retriedAt, now()->addDays(7));
            if (! empty($failedJob->uuid)) {
                Cache::put("job_retried_at_{$failedJob->uuid}", $retriedAt, now()->addDays(7));
            }

            // Retry using Laravel's Artisan queue:retry
            $identifier = $failedJob->uuid ?: $failedJob->id;
            Artisan::call('queue:retry', ['id' => [$identifier]]);

            // Log activity
            $this->activityLogService->log(
                eventType: 'queue.job.retry',
                category: 'monitoring',
                description: "Retried failed background job #{$failedJob->id} ({$failedJob->queue}).",
                metadata: [
                    'job_id' => $failedJob->id,
                    'uuid' => $failedJob->uuid,
                    'queue' => $failedJob->queue,
                    'actor_id' => $actorId,
                ]
            );

            return [
                'success' => true,
                'status' => 'retried',
                'message' => "Job {$id} was queued for retry successfully.",
                'data' => [
                    'id' => (string) $failedJob->id,
                    'uuid' => $failedJob->uuid,
                    'queue' => $failedJob->queue,
                    'retried_at' => $retriedAt,
                ],
                'code' => 200,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Parse serialized job payload safely without leaking raw parameters or credentials.
     */
    protected function parsePayload(string $rawPayload): array
    {
        $data = json_decode($rawPayload, true);
        if (! is_array($data)) {
            return [
                'display_name' => 'Unknown Job',
                'command_name' => null,
                'tenant_id' => null,
                'attempts' => 1,
            ];
        }

        $displayName = $data['displayName'] ?? ($data['job'] ?? 'Unknown Job');
        $commandName = null;
        $tenantId = null;

        // Try extracting command from data
        if (isset($data['data']['commandName'])) {
            $commandName = $data['data']['commandName'];
            $displayName = class_basename($commandName);
        }

        // Attempt resolving tenant ID from serialized command safely
        if (isset($data['data']['command'])) {
            $serialized = $data['data']['command'];
            // Safe regex to extract tenant_id if present without unserializing
            if (preg_match('/"tenant_id";(?:i:(\d+)|s:\d+:"(\d+)")/i', $serialized, $matches)) {
                $tenantId = (int) ($matches[1] ?: $matches[2]);
            }
        }

        return [
            'display_name' => class_basename($displayName),
            'command_name' => $commandName ?: $displayName,
            'tenant_id' => $tenantId,
            'attempts' => $data['attempts'] ?? 1,
            'max_tries' => $data['maxTries'] ?? null,
            'timeout' => $data['timeout'] ?? null,
        ];
    }

    /**
     * Resolve company or tenant name safely.
     */
    protected function resolveTenantName(?int $tenantId): ?string
    {
        if (! $tenantId) {
            return null;
        }

        return Cache::remember("tenant_name_{$tenantId}", 300, function () use ($tenantId) {
            return Tenant::where('id', $tenantId)->value('company_name');
        });
    }

    /**
     * Sanitize single-line error summary.
     */
    protected function sanitizeErrorSummary(?string $exception): ?string
    {
        if (! $exception) {
            return null;
        }

        $firstLine = strtok($exception, "\r\n");
        // Remove file paths and passwords
        $sanitized = preg_replace('/(\/[a-zA-Z0-9_\-\.]+)+/i', '[path]', $firstLine);
        $sanitized = preg_replace('/([a-zA-Z]:\\\\[a-zA-Z0-9_\-\\\.]+)/i', '[path]', $sanitized);
        $sanitized = preg_replace('/(password|secret|key|token)=[^&\s]+/i', '$1=[redacted]', $sanitized);

        return substr($sanitized, 0, 255);
    }

    /**
     * Sanitize full exception (removes secrets and sensitive connection strings).
     */
    protected function sanitizeFullException(?string $exception): ?string
    {
        if (! $exception) {
            return null;
        }

        // Redact credentials
        $sanitized = preg_replace('/(password|secret|key|token|access_token|authorization)=[^&\s\n]+/i', '$1=[redacted]', $exception);
        // Truncate to reasonable length (max 2000 chars)
        return substr($sanitized, 0, 2000);
    }
}
