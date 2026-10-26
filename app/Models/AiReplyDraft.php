<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReplyDraft extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'inbound_message_id',
        'knowledge_id',
        'suggested_reply',
        'edited_reply',
        'intent',
        'handoff_required',
        'handoff_reason',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'handoff_required' => 'boolean',
            'reviewed_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(
            WhatsAppMessage::class,
            'inbound_message_id'
        );
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(
            TenantKnowledge::class,
            'knowledge_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}