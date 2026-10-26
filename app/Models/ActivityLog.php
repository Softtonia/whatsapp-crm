<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_type',
        'event_type',
        'category',
        'description',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'request_id',
        'tenant_id',
        'status',
        'metadata',
        'old_values',
        'new_values',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce append-only immutability.
     * Prevents normal application code from updating or deleting historical audit logs.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Audit logs are immutable and cannot be updated.');
        });

        static::deleting(function () {
            // Allow deletion only if explicitly permitted (e.g. system purge command with flag)
            if (! app()->runningInConsole()) {
                throw new \RuntimeException('Audit logs are immutable and cannot be deleted via the application.');
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
