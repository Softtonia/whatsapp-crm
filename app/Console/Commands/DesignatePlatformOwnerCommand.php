<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DesignatePlatformOwnerCommand extends Command
{
    protected $signature = 'admin:designate-owner
                            {--email= : The email of the user to designate as platform owner}
                            {--user-id= : The ID of the user to designate as platform owner}';

    protected $description = 'Safely and idempotently designate a user as platform_owner';

    public function handle(ActivityLogService $activityLogService): int
    {
        $email = $this->option('email');
        $userId = $this->option('user-id');

        if (! $email && ! $userId) {
            $this->error('You must provide either --email or --user-id to designate a platform owner.');
            return self::FAILURE;
        }

        $user = User::query()
            ->when($userId, fn ($q) => $q->where('id', $userId))
            ->when($email, fn ($q) => $q->where('email', $email))
            ->first();

        if (! $user) {
            $this->error('User not found.');
            return self::FAILURE;
        }

        $role = Role::where('name', 'platform_owner')
            ->where('guard_name', 'web')
            ->first();

        if (! $role) {
            $this->error('Role platform_owner does not exist. Run db:seed --class=RoleSeeder first.');
            return self::FAILURE;
        }

        if ($user->hasRole('platform_owner')) {
            $this->info("User [{$user->id}] {$user->email} already has the platform_owner role.");
            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $role, $activityLogService) {
            $user->assignRole($role);

            $activityLogService->log(
                eventType: 'platform_owner_designated',
                category: 'security',
                description: "User [{$user->id}] designated as platform_owner via CLI command.",
                actor: null,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'assigned_role' => 'platform_owner',
                    'designated_via' => 'cli_artisan_command',
                ]
            );
        });

        $this->info("Successfully designated [{$user->id}] {$user->email} as platform_owner.");
        return self::SUCCESS;
    }
}
