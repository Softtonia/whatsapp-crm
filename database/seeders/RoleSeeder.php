<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            [
                'name' => 'platform_owner',
                'slug' => 'platform_owner',
                'description' => '<h2>Platform Owner</h2><p>Ultimate platform owner with immutable full access and privileges.</p>',
                'notes' => 'Protected system owner role. Cannot be deleted or modified.',
                'is_default' => true,
            ],
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'description' => '<h2>Super Admin</h2><p>Full access to all CRM modules and settings.</p><ul><li>Manage users and roles</li><li>Manage businesses and contacts</li><li>Manage WhatsApp accounts and conversations</li><li>Access all system settings</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Platform Admin',
                'slug' => 'platform_admin',
                'description' => '<h2>Platform Admin</h2><p>Operational administration, excluding owner-only role management and privilege delegation. Security access is read-only.</p>',
                'notes' => 'Built-in system role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Billing Admin',
                'slug' => 'billing_admin',
                'description' => '<h2>Billing Admin</h2><p>Dashboard, tenant read, plan management, subscription management, billing read, usage read.</p>',
                'notes' => 'Built-in system role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Support Operator',
                'slug' => 'support_operator',
                'description' => '<h2>Support Operator</h2><p>Dashboard, tenant read, plan read, subscription read, branding read, domains read, WhatsApp read, webhook read, integration read, usage read and monitoring read.</p>',
                'notes' => 'Built-in system role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Auditor',
                'slug' => 'auditor',
                'description' => '<h2>Auditor</h2><p>Dashboard read, tenant read, plan read, subscription read, billing read, WhatsApp read, webhook read, roles read, audit read, usage read and monitoring read.</p>',
                'notes' => 'Built-in system role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Administrator',
                'slug' => 'administrator',
                'description' => '<h2>Administrator</h2><p>Manage CRM operations and users.</p><ul><li>Manage assigned CRM operations</li><li>Manage contacts and leads</li><li>Monitor business activities</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Support Agent',
                'slug' => 'support_agent',
                'description' => '<h2>Support Agent</h2><p>Handle customer support and conversations.</p><ul><li>View assigned conversations</li><li>Respond to customer queries</li><li>Maintain support notes</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Billing',
                'slug' => 'billing',
                'description' => '<h2>Billing</h2><p>Manage billing and payment operations.</p><ul><li>Review billing information</li><li>Track payment status</li><li>Maintain billing records</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Technical',
                'slug' => 'technical',
                'description' => '<h2>Technical</h2><p>Handle technical operations.</p><ul><li>Investigate technical issues</li><li>Support system troubleshooting</li><li>Maintain technical records</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Reseller',
                'slug' => 'reseller',
                'description' => '<h2>Reseller</h2><p>Manage reseller operations.</p><ul><li>Manage reseller-related activities</li><li>Review assigned business information</li><li>Monitor relevant customer operations</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Client',
                'slug' => 'client',
                'description' => '<h2>Client</h2><p>Standard client access.</p><ul><li>View permitted CRM information</li><li>Access assigned services</li><li>Manage permitted account activities</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
            [
                'name' => 'Tenant',
                'slug' => 'tenant',
                'description' => '<h2>Tenant</h2><p>Tenant user access.</p><ul><li>View tenant-specific CRM information</li><li>Access tenant-specific services</li><li>Manage tenant-specific account activities</li></ul>',
                'notes' => 'System role. Cannot be deleted.',
                'is_default' => true,
            ],
        ];

        foreach ($roles as $data) {
            Role::updateOrCreate(
                [
                    'name' => $data['name'],
                    'guard_name' => 'web',
                ],
                $data + ['guard_name' => 'web']
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
