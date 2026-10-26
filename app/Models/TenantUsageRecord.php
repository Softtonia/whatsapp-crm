<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'whatsapp_messages_count',
        'ai_replies_count',
        'campaigns_count',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'whatsapp_messages_count' => 'integer',
        'ai_replies_count' => 'integer',
        'campaigns_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function business(): BelongsTo
    {
        return $this->tenant();
    }
}
