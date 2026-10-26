<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantStatusHistory extends Model
{
    protected $table = 'tenant_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'previous_status',
        'new_status',
        'reason',
        'performed_by',
        'effective_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
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

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
