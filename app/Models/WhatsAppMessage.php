<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'sent_by_user_id',
        'provider_message_id',
        'direction',
        'type',
        'body',
        'media_id',
        'media_mime_type',
        'media_sha256',
        'media_filename',
        'status',
        'context',
        'payload',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
