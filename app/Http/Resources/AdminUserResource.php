<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $full = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $full !== '' ? $full : ($this->name ?? ''),
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'status' => $this->status ?? 'active',
            'is_active' => ($this->status ?? 'active') === 'active',
            'roles' => $this->relationLoaded('roles')
                ? $this->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                ])
                : $this->roles()->get(['roles.id', 'roles.name', 'roles.slug']),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
