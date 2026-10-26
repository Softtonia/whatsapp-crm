<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOffboardingStep extends Model
{
    protected $table = 'tenant_offboarding_steps';

    protected $fillable = [
        'offboarding_job_id',
        'step_key',
        'status',
        'attempts',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(
            TenantOffboardingJob::class,
            'offboarding_job_id'
        );
    }
}
