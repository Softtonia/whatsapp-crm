<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSecurityChallenge extends Model
{
    protected $table = 'admin_security_challenges';

    protected $fillable = [
        'user_id',
        'purpose',
        'target_email',
        'otp_hash',
        'otp_encrypted',
        'expires_at',
        'verified_at',
        'auth_token_hash',
        'auth_token_expires_at',
        'attempts',
        'resend_count',
        'last_sent_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'auth_token_expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
