<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'first_name',
    'last_name',
    'phone_number',
    'name',
    'email',
    'password',
    'status',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the tenants associated with the user.
     */
    public function tenants()
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_users',
            'user_id',
            'tenant_id'
        )
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    /**
     * Backward compatibility alias for tenants().
     */
    public function businesses()
    {
        return $this->tenants();
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim(
            ($this->first_name ?? '') . ' ' .
                ($this->last_name ?? '')
        );
    }

    public function tenantActivityLogs(): HasMany
    {
        return $this->hasMany(TenantActivityLog::class, 'actor_user_id');
    }

    public function tenantStatusChanges(): HasMany
    {
        return $this->hasMany(TenantStatusHistory::class, 'performed_by');
    }

    public function tenantSubscriptionEvents(): HasMany
    {
        return $this->hasMany(TenantSubscriptionEvent::class, 'performed_by');
    }

    public function tenantInternalNotes(): HasMany
    {
        return $this->hasMany(TenantInternalNote::class, 'author_user_id');
    }

    public function initiatedTenantBulkOperations(): HasMany
    {
        return $this->hasMany(TenantBulkOperation::class, 'initiated_by');
    }

    public function requestedTenantExports(): HasMany
    {
        return $this->hasMany(TenantExport::class, 'requested_by');
    }

    public function requestedOffboardingJobs(): HasMany
    {
        return $this->hasMany(TenantOffboardingJob::class, 'requested_by');
    }

    public function approvedOffboardingJobs(): HasMany
    {
        return $this->hasMany(TenantOffboardingJob::class, 'approved_by');
    }

    public function adminSessions(): HasMany
    {
        return $this->hasMany(AdminSession::class, 'user_id');
    }

    public function mfaSetting(): HasOne
    {
        return $this->hasOne(AdminMfaSetting::class, 'user_id');
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(AdminMfaRecoveryCode::class, 'user_id');
    }

    public function mfaChallenges(): HasMany
    {
        return $this->hasMany(AdminMfaChallenge::class, 'user_id');
    }
}
