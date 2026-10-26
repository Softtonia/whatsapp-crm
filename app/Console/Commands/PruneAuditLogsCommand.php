<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'audit:prune
                            {--days= : Retention period in days (default from config or 365)}
                            {--force : Force pruning without interactive confirmation}';

    protected $description = 'Prune old audit logs based on the compliance retention policy';

    public function handle(ActivityLogService $activityLogService): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 365));

        if ($days < 30) {
            $this->error('Retention period cannot be less than 30 days for compliance and legal purposes.');
            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $protectedCategories = config('audit.protected_categories', ['security_investigation', 'legal_hold']);

        $query = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            // Exclude critical security investigations and compliance events from automated pruning
            ->whereNotIn('category', $protectedCategories);

        $count = $query->count();

        if ($count === 0) {
            $this->info("No audit logs older than {$days} days ({$cutoff->toDateString()}) found to prune.");
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Are you sure you want to permanently delete {$count} audit log records older than {$cutoff->toDateString()}?")) {
            $this->info('Audit log pruning cancelled.');
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $activityLogService->log(
            eventType: 'audit.logs_pruned',
            category: 'security',
            description: "Pruned {$deleted} audit log records older than {$days} days ({$cutoff->toDateString()}).",
            actor: null,
            subjectType: ActivityLog::class,
            metadata: [
                'retention_days' => $days,
                'cutoff_date' => $cutoff->toIso8601String(),
                'deleted_count' => $deleted,
            ]
        );

        $this->info("Successfully pruned {$deleted} audit log records.");
        return self::SUCCESS;
    }
}
