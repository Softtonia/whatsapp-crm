<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $users = [
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'vijay.kumar@softtonia.com',
                'phone' => '1234567890',
                'role' => 'Super Admin',
            ],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'administrator@example.com',
                'phone' => '0987654321',
                'role' => 'Administrator',
            ],
            [
                'first_name' => 'Support',
                'last_name' => 'Agent',
                'email' => 'support@example.com',
                'phone' => '1111111111',
                'role' => 'Support Agent',
            ],
            [
                'first_name' => 'Billing',
                'last_name' => 'User',
                'email' => 'billing@example.com',
                'phone' => '2222222222',
                'role' => 'Billing',
            ],
            [
                'first_name' => 'Technical',
                'last_name' => 'User',
                'email' => 'technical@example.com',
                'phone' => '3333333333',
                'role' => 'Technical',
            ],
            [
                'first_name' => 'Reseller',
                'last_name' => 'User',
                'email' => 'reseller@example.com',
                'phone' => '4444444444',
                'role' => 'Reseller',
            ],
            [
                'first_name' => 'Client',
                'last_name' => 'User',
                'email' => 'client@example.com',
                'phone' => '5555555555',
                'role' => 'Client',
            ],
        ];

        foreach ($users as $data) {
            $role = Role::where('name', $data['role'])
                ->where('guard_name', 'web')
                ->firstOrFail();

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'phone_number' => $data['phone'],
                    'password' => Hash::make(
                        'Soft@12345'
                    ),
                ]
            );

            // Ensure the user's role matches this seeder.
            $user->syncRoles([$role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
