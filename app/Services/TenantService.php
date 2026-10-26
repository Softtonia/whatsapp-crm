<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantService
{
    public function createForUser(User $user, array $data): Tenant
    {
        return DB::transaction(function () use ($user, $data) {
            $name = trim($data['company_name'] ?? $data['name'] ?? '');
            $baseSlug = Str::slug($data['slug'] ?? $name);

            if ($baseSlug === '') {
                throw ValidationException::withMessages([
                    'slug' => 'A valid tenant slug is required.',
                ]);
            }

            $slug = $baseSlug;
            $counter = 1;

            // Unique DB index remains the final concurrency safeguard.
            while (Tenant::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            $tenant = Tenant::create([
                'company_name' => $name,
                'slug' => $slug,
                'status' => 'active',
            ]);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
            ]);

            return $tenant;
        });
    }

    public function listForUser(User $user): Collection
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->where('slug', 'super_admin')->exists()) {
            return Tenant::query()->orderBy('id')->get();
        }

        return $user->tenants()
            ->wherePivot('status', 'active')
            ->orderBy('tenants.id')
            ->get();
    }

    public function findForUser(User $user, Tenant $tenant): Tenant
    {
        if (! $user->can('view', $tenant)) {
            abort(403, 'You are not authorized to access this tenant.');
        }

        return $tenant;
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $locked->update($data);

            return $locked->refresh();
        });
    }

    public function delete(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $locked->delete();
        });
    }

    public function bulkDelete(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'ids' => 'Select at least one tenant.',
            ]);
        }

        return DB::transaction(function () use ($ids) {
            $tenants = Tenant::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tenants->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => 'One or more selected tenants do not exist.',
                ]);
            }

            foreach ($tenants as $tenant) {
                $tenant->delete();
            }

            return $tenants->count();
        });
    }
}
