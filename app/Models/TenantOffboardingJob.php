<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantOffboardingJob extends Model
{
    protected $table = 'tenant_offboarding_jobs';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'approved_by',
        'status',
        'reason',
        'options',
        'metadata',
        'scheduled_for',
        'started_at',
        'completed_at',
        'cancelled_at',
        'archived_at',
        'retention_until',
        'purge_eligible_at',
        'purge_status',
        'purge_requested_by',
        'purge_approved_by',
        'purge_requested_at',
        'purge_approved_at',
        'purge_started_at',
        'purge_completed_at',
        'purge_reason',
        'purge_preview',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'metadata' => 'array',
            'purge_preview' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
            'retention_until' => 'datetime',
            'purge_eligible_at' => 'datetime',
            'purge_requested_at' => 'datetime',
            'purge_approved_at' => 'datetime',
            'purge_started_at' => 'datetime',
            'purge_completed_at' => 'datetime',
            'purge_requested_by' => 'integer',
            'purge_approved_by' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(
            TenantOffboardingStep::class,
            'offboarding_job_id'
        );
    }
}
