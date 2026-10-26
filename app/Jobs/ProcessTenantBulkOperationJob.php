<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantBulkOperation;
use App\Models\TenantBulkOperationItem;
use App\Models\TenantStatusHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTenantBulkOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $operationId
    ) {}

    /**
     * Process a tenant bulk operation.
     */
    public function handle(): void
    {
        $shouldProcess = DB::transaction(function (): bool {
            $operation = TenantBulkOperation::query()
                ->lockForUpdate()
                ->find($this->operationId);

            if (! $operation) {
                return false;
            }

            // Never restart a cancelled or terminal operation.
            if (! in_array(
                $operation->status,
                ['pending', 'processing'],
                true
            )) {
                return false;
            }

            $operation->update([
                'status' => 'processing',
                'started_at' => $operation->started_at ?? now(),
                'completed_at' => null,
            ]);

            return true;
        });

        if (! $shouldProcess) {
            return;
        }

        TenantBulkOperationItem::query()
            ->where('bulk_operation_id', $this->operationId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $itemId): void {
                $this->processItem($itemId);
            });

        $this->refreshOperationStatus($this->operationId);
    }

    /**
     * Process one tenant item.
     */
    private function processItem(int $itemId): void
    {
        try {
            DB::transaction(function () use ($itemId): void {
                // Read the operation ID first. The item is locked only
                // after acquiring the operation lock.
                $itemReference = TenantBulkOperationItem::query()
                    ->findOrFail($itemId);

                $operation = TenantBulkOperation::query()
                    ->lockForUpdate()
                    ->findOrFail($itemReference->bulk_operation_id);

                // Cancellation or another terminal transition may have
                // happened after the job initially started.
                if (! in_array(
                    $operation->status,
                    ['pending', 'processing'],
                    true
                )) {
                    return;
                }

                $item = TenantBulkOperationItem::query()
                    ->lockForUpdate()
                    ->findOrFail($itemId);

                // Prevent duplicate processing and preserve cancelled
                // or completed items.
                if ($item->status !== 'pending') {
                    return;
                }

                $tenant = Tenant::query()
                    ->whereKey($item->tenant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $action = $operation->operation_type;
                $options = $operation->options ?? [];

                $newStatus = match ($action) {
                    'activate' => 'active',
                    'suspend' => 'suspended',
                    'archive' => 'archived',
                    default => throw new \RuntimeException(
                        'Unsupported tenant operation.'
                    ),
                };

                $reason = $action === 'suspend'
                    ? ($options['reason'] ?? null)
                    : null;

                $previousStatus = $tenant->status;

                $oldValues = [
                    'status' => $previousStatus,
                    'suspend_reason' => $tenant->suspend_reason,
                ];

                $tenant->status = $newStatus;

                if ($action === 'activate') {
                    $tenant->suspend_reason = null;
                } elseif ($action === 'suspend') {
                    $tenant->suspend_reason = $reason;
                }

                $tenant->save();

                $newValues = [
                    'status' => $tenant->status,
                    'suspend_reason' => $tenant->suspend_reason,
                ];

                $eventType = "tenant.{$action}";

                if ($previousStatus !== $newStatus) {
                    TenantStatusHistory::create([
                        'tenant_id' => $tenant->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $newStatus,
                        'reason' => $reason,
                        'performed_by' => $operation->initiated_by,
                        'effective_at' => now(),
                        'metadata' => [
                            'source' => 'bulk_operation',
                            'operation_id' => $operation->id,
                        ],
                    ]);
                }

                TenantActivityLog::create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $operation->initiated_by,
                    'actor_type' => 'user',
                    'event_type' => $eventType,
                    'description' => "Bulk operation {$operation->id}: {$eventType}",
                    'subject_type' => Tenant::class,
                    'subject_id' => $tenant->id,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);

                $item->update([
                    'status' => 'completed',
                    'message' => 'Tenant operation completed successfully.',
                    'result' => [
                        'previous_status' => $previousStatus,
                        'new_status' => $newStatus,
                    ],
                    'processed_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            // Do not overwrite cancelled or already completed items.
            TenantBulkOperationItem::query()
                ->whereKey($itemId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'message' => 'Tenant operation failed.',
                    'result' => [
                        'error' => $exception->getMessage(),
                    ],
                    'processed_at' => now(),
                ]);

            Log::error('Tenant bulk operation item failed', [
                'operation_id' => $this->operationId,
                'item_id' => $itemId,
                'error' => $exception->getMessage(),
            ]);
        }

        $item = TenantBulkOperationItem::query()->find($itemId);

        if ($item) {
            $this->refreshOperationStatus($item->bulk_operation_id);
        }
    }

    /**
     * Recalculate operation counters without overwriting cancellation.
     */
    private function refreshOperationStatus(int $operationId): void
    {
        DB::transaction(function () use ($operationId): void {
            $operation = TenantBulkOperation::query()
                ->lockForUpdate()
                ->find($operationId);

            if (! $operation) {
                return;
            }

            // Cancellation is terminal for this job. A stale worker must
            // never change it back to processing or completed.
            if ($operation->status === 'cancelled') {
                return;
            }

            $items = $operation->items();

            $total = (clone $items)->count();

            $successful = (clone $items)
                ->where('status', 'completed')
                ->count();

            $failed = (clone $items)
                ->where('status', 'failed')
                ->count();

            $cancelled = (clone $items)
                ->where('status', 'cancelled')
                ->count();

            $pending = (clone $items)
                ->whereIn('status', ['pending', 'processing'])
                ->count();

            $status = $pending > 0
                ? 'processing'
                : (
                    $failed === 0
                        ? 'completed'
                        : (
                            $successful === 0
                                ? 'failed'
                                : 'partial_failed'
                        )
                );

            $operation->update([
                'status' => $status,
                'total_items' => $total,
                'processed_items' => $successful + $failed + $cancelled,
                'successful_items' => $successful,
                'failed_items' => $failed,
                'error_summary' => $failed > 0
                    ? "{$failed} tenant item(s) failed."
                    : null,
                'completed_at' => $pending === 0 ? now() : null,
            ]);
        });
    }
}
