<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminMfaSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'totp_secret_encrypted',
        'is_enabled',
        'confirmed_at',
    ];

    protected $hidden = [
        'totp_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
