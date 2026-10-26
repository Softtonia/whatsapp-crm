<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'tenant_id',
        'whatsapp_account_id',
        'customer_phone',
        'customer_name',
        'status',
        'assigned_to',
        'unread_count',
        'is_human_takeover',
        'last_message_at',
        'last_message_preview',
    ];

    protected function casts(): array
    {
        return [
            'is_human_takeover' => 'boolean',
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
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

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(TenantWhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function scopeWithInboxData(Builder $query): Builder
    {
        return $query->with(['assignee:id,first_name,last_name,email'])
            ->withCount(['messages as messages_count']);
    }
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
