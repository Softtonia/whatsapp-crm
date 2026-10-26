<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_sensitive',
        'description',
    ];

    /**
     * Get typed and decrypted value.
     */
    public function getTypedValueAttribute(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'json' => json_decode($this->value, true),
            'encrypted' => $this->decryptValue($this->value),
            default => (string) $this->value,
        };
    }

    /**
     * Set typed and encrypted value.
     */
    public function setTypedValue(mixed $value, string $type = 'string', bool $isSensitive = false): void
    {
        $this->type = $type;
        $this->is_sensitive = $isSensitive;

        if ($value === null) {
            $this->value = null;
            return;
        }

        $this->value = match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) ((int) $value),
            'json' => is_string($value) ? $value : json_encode($value),
            'encrypted' => is_string($value) ? Crypt::encryptString($value) : Crypt::encryptString(json_encode($value)),
            default => (string) $value,
        };
    }

    protected function decryptValue(?string $val): ?string
    {
        if (empty($val)) {
            return null;
        }

        try {
            return Crypt::decryptString($val);
        } catch (Throwable) {
            return $val;
        }
    }
}
