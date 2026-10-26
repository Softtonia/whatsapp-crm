<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantExport extends Model
{
    protected $table = 'tenant_exports';

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'export_type',
        'format',
        'status',
        'filters',
        'file_disk',
        'file_path',
        'file_size_bytes',
        'record_count',
        'failure_reason',
        'started_at',
        'completed_at',
        'expires_at',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'file_size_bytes' => 'integer',
            'record_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
