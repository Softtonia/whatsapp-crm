<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->isActiveMember($user, $tenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->isOwner($user, $tenant);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }

    private function isOwner(User $user, Tenant $tenant): bool
    {
        return $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->exists();
    }

    private function isActiveMember(User $user, Tenant $tenant): bool
    {
        return $tenant->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
