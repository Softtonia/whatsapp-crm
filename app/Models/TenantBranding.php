<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TenantBranding extends Model
{
    use HasFactory;

    protected $table = 'tenant_brandings';

    protected $fillable = [
        'tenant_id',
        'brand_name',
        'logo_path',
        'favicon_path',
        'primary_color',
        'secondary_color',
        'support_email',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    public function getFaviconUrlAttribute(): ?string
    {
        if (! $this->favicon_path) {
            return null;
        }

        return Storage::disk('public')->url($this->favicon_path);
    }
}
