<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactInteraction extends Model
{
    protected $fillable = [
        'contact_id',
        'conversation_id',
        'whatsapp_message_id',
        'type',
        'summary',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(
            WhatsAppMessage::class,
            'whatsapp_message_id'
        );
    }
}
