<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantWhatsAppAccount extends Model
{
    protected $table = 'tenant_whats_app_accounts';

    protected $fillable = [
        'tenant_id',
        'phone_number_id',
        'waba_id',
        'business_name',
        'display_phone_number',
        'quality_rating',
        'access_token',
        'status',
        'last_synced_at',
        'last_sync_error',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
