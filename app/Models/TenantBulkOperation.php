<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBulkOperation extends Model
{
    protected $table = 'tenant_bulk_operations';

    protected $fillable = [
        'operation_type',
        'status',
        'initiated_by',
        'filters',
        'options',
        'total_items',
        'processed_items',
        'successful_items',
        'failed_items',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'options' => 'array',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'successful_items' => 'integer',
            'failed_items' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            TenantBulkOperationItem::class,
            'bulk_operation_id'
        );
    }
}
