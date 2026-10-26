<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'notes',
        'is_default',
        'guard_name',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Role $role) {
            if (empty($role->slug) && ! empty($role->name)) {
                $role->slug = \Illuminate\Support\Str::slug($role->name, '_');
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            config('permission.table_names.model_has_roles'),
            'role_id',
            config('permission.column_names.model_morph_key', 'model_id')
        )
            ->wherePivot('model_type', (new User())->getMorphClass())
            ->withTimestamps();
    }
}