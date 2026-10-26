<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantOffboardingJob as OffboardingRequest;
use App\Models\TenantStatusHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTenantOffboardingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $offboardingJobId
    ) {}

    public function handle(): void
    {
        $shouldProcess = DB::transaction(function (): bool {
            $job = OffboardingRequest::query()
                ->lockForUpdate()
                ->find($this->offboardingJobId);

            if (! $job) {
                return false;
            }
            if (
                ($job->options['operation'] ?? null) === 'purge_request'
                || ($job->purge_status ?? 'not_requested') !== 'not_requested'
            ) {
                return false;
            }
            if (
                $job->status === 'approved'
                && $job->scheduled_for?->isFuture()
            ) {
                return false;
            }

            $metadata = $job->metadata ?? [];

            $isExplicitRetry = $job->status === 'pending'
                && ($metadata['retry_execution_pending_worker'] ?? false) === true;

            if (
                ! in_array(
                    $job->status,
                    ['approved', 'processing'],
                    true
                )
                && ! $isExplicitRetry
            ) {
                return false;
            }

            unset($metadata['retry_execution_pending_worker']);

            $job->update([
                'status' => 'processing',
                'started_at' => $job->started_at ?? now(),
                'completed_at' => null,
                'metadata' => $metadata,
            ]);

            return true;
        });

        if (! $shouldProcess) {
            return;
        }

        try {
            $this->executeArchiveStep();
            $this->markCompleted();
        } catch (Throwable $exception) {
            Log::error('Tenant offboarding attempt failed.', [
                'offboarding_job_id' => $this->offboardingJobId,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Archive the tenant and record the retention deadline.
     *
     * This step never deletes tenant business data.
     */
    private function executeArchiveStep(): void
    {
        DB::transaction(function (): void {
            $job = OffboardingRequest::query()
                ->lockForUpdate()
                ->findOrFail($this->offboardingJobId);

            if ($job->status !== 'processing') {
                return;
            }

            $step = $job->steps()
                ->where('step_key', 'archive_tenant')
                ->lockForUpdate()
                ->first();

            if (! $step) {
                $step = $job->steps()->create([
                    'step_key' => 'archive_tenant',
                    'status' => 'pending',
                    'attempts' => 0,
                ]);
            }

            if ($step->status === 'completed') {
                return;
            }

            $step->update([
                'status' => 'processing',
                'attempts' => $step->attempts + 1,
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
            ]);

            $tenant = Tenant::query()
                ->lockForUpdate()
                ->findOrFail($job->tenant_id);

            $previousStatus = $tenant->status;
            $archivedAt = $tenant->archived_at ?? now();
            $retentionUntil = $archivedAt->copy()->addDays(90);

            $tenant->forceFill([
                'status' => 'archived',
                'archived_at' => $archivedAt,
            ])->save();

            $job->forceFill([
                'archived_at' => $archivedAt,
                'retention_until' => $retentionUntil,
                'purge_eligible_at' => $retentionUntil,
            ])->save();

            if ($previousStatus !== 'archived') {
                TenantStatusHistory::create([
                    'tenant_id' => $tenant->id,
                    'previous_status' => $previousStatus,
                    'new_status' => 'archived',
                    'reason' => $job->reason,
                    'performed_by' => $job->approved_by,
                    'effective_at' => $archivedAt,
                    'metadata' => [
                        'source' => 'tenant_offboarding',
                        'offboarding_job_id' => $job->id,
                        'retention_until' => $retentionUntil->toISOString(),
                    ],
                ]);

                TenantActivityLog::create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $job->approved_by,
                    'actor_type' => 'user',
                    'event_type' => 'tenant.offboarding_archived',
                    'description' => 'Tenant archived through approved offboarding workflow.',
                    'subject_type' => Tenant::class,
                    'subject_id' => $tenant->id,
                    'old_values' => [
                        'status' => $previousStatus,
                    ],
                    'new_values' => [
                        'status' => 'archived',
                        'archived_at' => $archivedAt->toISOString(),
                        'retention_until' => $retentionUntil->toISOString(),
                    ],
                ]);
            }

            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
                'error_message' => null,
                'metadata' => [
                    'tenant_status' => 'archived',
                    'archived_at' => $archivedAt->toISOString(),
                    'retention_until' => $retentionUntil->toISOString(),
                    'data_deleted' => false,
                ],
            ]);
        });
    }

    /**
     * Complete the workflow only after the archive step succeeds.
     */
    private function markCompleted(): void
    {
        DB::transaction(function (): void {
            $job = OffboardingRequest::query()
                ->lockForUpdate()
                ->find($this->offboardingJobId);

            if (! $job || $job->status !== 'processing') {
                return;
            }

            $unfinishedSteps = $job->steps()
                ->where('step_key', 'archive_tenant')
                ->where('status', '!=', 'completed')
                ->exists();

            if ($unfinishedSteps) {
                throw new \RuntimeException(
                    'Cannot complete offboarding while archive step is unfinished.'
                );
            }

            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => array_merge(
                    $job->metadata ?? [],
                    [
                        'workflow_completed_at' => now()->toISOString(),
                        'data_deleted' => false,
                    ]
                ),
            ]);
        });
    }

    /**
     * Record the final failure after all queue attempts are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $job = OffboardingRequest::query()
                ->lockForUpdate()
                ->find($this->offboardingJobId);

            if (! $job || $job->status !== 'processing') {
                return;
            }

            $message = mb_substr(
                $exception?->getMessage() ?? 'Queue attempts exhausted.',
                0,
                5000
            );

            $step = $job->steps()
                ->where('step_key', 'archive_tenant')
                ->lockForUpdate()
                ->first();

            if (! $step) {
                $job->steps()->create([
                    'step_key' => 'archive_tenant',
                    'status' => 'failed',
                    'attempts' => 0,
                    'error_message' => $message,
                ]);
            } elseif ($step->status !== 'completed') {
                $step->update([
                    'status' => 'failed',
                    'error_message' => $message,
                ]);
            }

            $job->update([
                'status' => 'failed',
                'metadata' => array_merge(
                    $job->metadata ?? [],
                    [
                        'last_error' => mb_substr($message, 0, 1000),
                        'failed_at' => now()->toISOString(),
                    ]
                ),
            ]);
        });

        Log::error('Tenant offboarding exhausted all queue attempts.', [
            'offboarding_job_id' => $this->offboardingJobId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
