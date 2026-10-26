<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBulkOperationItem extends Model
{
    protected $table = 'tenant_bulk_operation_items';

    protected $fillable = [
        'bulk_operation_id',
        'tenant_id',
        'item_type',
        'item_id',
        'status',
        'idempotency_key',
        'message',
        'result',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'result' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(
            TenantBulkOperation::class,
            'bulk_operation_id'
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }
}
