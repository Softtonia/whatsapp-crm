<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Authentication
            'admin.me',
            'admin.logout',

            // Roles
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',

            // Permission Management
            'permissions.view',
            'permissions.create',
            'permissions.update',
            'permissions.delete',
            'roles.permissions.view',
            'roles.permissions.update',

            // Users and Admin Management
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.activate',
            'users.deactivate',

            // Businesses and Tenants
            'businesses.view',
            'businesses.create',
            'businesses.update',
            'businesses.delete',
            'tenants.view',
            'tenants.create',
            'tenants.update',
            'tenants.delete',
            'tenants.activate',
            'tenants.deactivate',
            'tenants.suspend',
            'tenants.archive',
            'tenants.restore',
            'tenants.members.view',
            'tenants.members.create',
            'tenants.members.update',
            'tenants.members.delete',
            'tenants.owner.transfer',
            'tenants.profile.view',
            'tenants.profile.update',

            // Tenant White-Label Branding & Domains (SA-08)
            'branding.read',
            'branding.update',
            'domains.read',
            'domains.manage',

            // Tenant Onboarding
            'tenants.onboarding.view',
            'tenants.onboarding.update',
            'tenants.onboarding.complete',

            // Tenant Subscriptions and Billing
            'tenants.subscriptions.view',
            'tenants.subscriptions.create',
            'tenants.subscriptions.update',
            'tenants.subscriptions.cancel',
            'tenants.subscriptions.renew',
            'tenants.billing.view',
            'tenants.billing.update',
            'tenants.usage.view',
            'tenants.subscription-events.view',

            // Tenant Governance and Audit
            'tenants.activity.view',
            'tenants.status-history.view',
            'tenants.notes.view',
            'tenants.notes.create',
            'tenants.notes.update',
            'tenants.notes.delete',
            'tenants.notes.pin',
            'tenants.health.view',
            'tenants.health-checks.view',
            'tenants.internal-notes.view',

            // Tenant Bulk Operations
            'tenants.bulk-operations.view',
            'tenants.bulk-operations.create',
            'tenants.bulk-operations.cancel',
            'tenants.bulk-operations.retry',

            // Tenant Exports
            'tenants.exports.view',
            'tenants.exports.create',
            'tenants.exports.download',
            'tenants.exports.cancel',

            // Tenant Offboarding
            'tenant-offboarding.view',
            'tenant-offboarding.create',
            'tenant-offboarding.approve',
            'tenant-offboarding.reject',
            'tenant-offboarding.cancel',
            'tenant-offboarding.retry',

            // Tenant Data Purge
            'tenant.purge.preview',
            'tenant.purge.request',
            'tenant.purge.approve',
            'tenant.purge.reject',
            'tenant.purge.execute',
            'tenant.purge.view',
            'tenant.purge.retry',

            // WhatsApp Administration & Integrations (SA-09)
            'integrations.read',
            'integrations.manage',
            'whatsapp.read',
            'whatsapp.sync',
            'whatsapp.webhooks.read',
            'whatsapp.webhooks.retry',

            // WhatsApp Accounts
            'whatsapp_accounts.view',
            'whatsapp_accounts.create',
            'whatsapp_accounts.update',
            'whatsapp_accounts.delete',
            'whatsapp_accounts.connect',
            'whatsapp_accounts.disconnect',

            // Inbox Conversations
            'inbox.conversations.view',
            'inbox.conversations.create',
            'inbox.conversations.update',
            'inbox.conversations.delete',
            'inbox.conversations.read',
            'inbox.conversations.assign',

            // Inbox Messages
            'inbox.messages.view',
            'inbox.messages.send',
            'inbox.messages.delete',
            'inbox.media.view',

            // Inbox Notes
            'inbox.notes.view',
            'inbox.notes.create',
            'inbox.notes.update',
            'inbox.notes.delete',

            // Contacts
            'contacts.view',
            'contacts.create',
            'contacts.update',
            'contacts.delete',
            'contacts.import',
            'contacts.export',
            'contacts.import_template',

            // Contact Interactions
            'contact-interactions.view',
            'contact-interactions.create',

            // Lead Pipeline
            'lead_pipeline.view',
            'lead_pipeline.create',
            'lead_pipeline.update',
            'lead_pipeline.delete',

            // Leads
            'leads.view',
            'leads.create',
            'leads.update',
            'leads.delete',
            'leads.assign',
            'leads.notes.view',
            'leads.notes.create',
            'leads.notes.update',
            'leads.notes.delete',

            // AI Knowledge
            'ai.knowledge.view',
            'ai.knowledge.create',
            'ai.knowledge.update',
            'ai.knowledge.delete',
            'ai.knowledge.approve',
            'ai.knowledge.reject',

            // AI Drafts
            'ai.drafts.view',
            'ai.drafts.create',
            'ai.drafts.update',
            'ai.drafts.delete',
            'ai.drafts.approve',
            'ai.drafts.reject',

            // Products
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'products.import',
            'products.export',

            // Email Templates
            'email_templates.view',
            'email_templates.create',
            'email_templates.update',
            'email_templates.delete',
            'email_templates.preview',

            // Campaigns
            'campaigns.view',
            'campaigns.create',
            'campaigns.update',
            'campaigns.delete',
            'campaigns.send',
            'campaigns.cancel',
            'campaigns.report.view',

            // Notifications
            'notifications.view',
            'notifications.create',
            'notifications.update',
            'notifications.delete',
            'notifications.send',

            // Notification Devices
            'notification-devices.view',
            'notification-devices.register',
            'notification-devices.delete',

            // System Activity & Security Audit Logs (SA-11)
            'system-activity.view',
            'system-activity.export',
            'audit.read',
            'security.read',

            // System Settings & Security Configuration (SA-12)
            'settings.view',
            'settings.update',
            'settings.read',
            'settings.manage',
            'security.manage',

            // Usage Monitoring and Quotas (SA-13)
            'usage.read',
            'usage.manage',

            // System Health & Background Jobs (SA-14)
            'monitoring.read',
            'monitoring.retry',

            // RBAC Matrix Atomic Permissions (SA Matrix Reconciliation)
            'dashboard.read',
            'tenants.read',
            'tenants.provision',
            'tenants.reactivate',
            'plans.read',
            'plans.create',
            'plans.update',
            'plans.archive',
            'subscriptions.read',
            'subscriptions.assign',
            'subscriptions.update',
            'subscriptions.cancel',
            'billing.read',
            'admin_users.read',
            'admin_users.create',
            'admin_users.update',
            'admin_users.deactivate',
            'roles.read',
            'roles.manage',

            // Reports and Analytics
            'reports.view',
            'reports.export',
            'analytics.view',
        ];

        $permissions = array_values(array_unique($permissions));

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $allPermissions = Permission::where('guard_name', 'web')->get();

        // 1. Platform Owner: All permissions
        $platformOwner = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'platform_owner')->orWhere('slug', 'platform_owner'))
            ->first();

        if ($platformOwner) {
            $platformOwner->syncPermissions($allPermissions);
        }

        // 2. Super Admin: Legacy administrative equivalent to platform owner (all permissions)
        $superAdmin = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'Super Admin')->orWhere('slug', 'super_admin'))
            ->first();

        if ($superAdmin) {
            $superAdmin->syncPermissions($allPermissions);
        }

        // 3. Platform Admin:
        // Operational administration, excluding owner-only role management and privilege delegation. Security access is read-only.
        // Specifically excludes: roles.manage, security.manage
        $platformAdminPermissions = [
            'dashboard.read',
            'tenants.read', 'tenants.create', 'tenants.update', 'tenants.suspend', 'tenants.reactivate', 'tenants.provision',
            'plans.read', 'plans.create', 'plans.update', 'plans.archive',
            'subscriptions.read', 'subscriptions.assign', 'subscriptions.update', 'subscriptions.cancel',
            'billing.read',
            'branding.read', 'branding.update',
            'domains.read', 'domains.manage',
            'whatsapp.read', 'whatsapp.sync',
            'whatsapp.webhooks.read', 'whatsapp.webhooks.retry',
            'admin_users.read', 'admin_users.create', 'admin_users.update', 'admin_users.deactivate',
            'roles.read',
            'audit.read',
            'security.read',
            'settings.read', 'settings.manage',
            'integrations.read', 'integrations.manage',
            'usage.read',
            'monitoring.read', 'monitoring.retry',
            // Also include legacy equivalent read/operational permissions
            'tenants.view', 'tenants.activate', 'tenants.deactivate', 'tenants.archive', 'tenants.restore',
            'tenants.members.view', 'tenants.members.create', 'tenants.members.update', 'tenants.members.delete',
            'tenants.profile.view', 'tenants.profile.update',
            'users.view', 'users.create', 'users.update', 'users.activate', 'users.deactivate',
            'roles.view', 'permissions.view', 'roles.permissions.view',
            'admin.me', 'admin.logout',
        ];
        $platformAdminRole = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'Platform Admin')->orWhere('slug', 'platform_admin'))
            ->first();
        if ($platformAdminRole) {
            $platformAdminRole->syncPermissions(
                Permission::where('guard_name', 'web')->whereIn('name', $platformAdminPermissions)->get()
            );
        }

        // 4. Billing Admin:
        // Dashboard, tenant read, plan management, subscription management, billing read, usage read, monitoring excluded.
        $billingAdminPermissions = [
            'dashboard.read',
            'tenants.read', 'tenants.view',
            'plans.read', 'plans.create', 'plans.update', 'plans.archive',
            'subscriptions.read', 'subscriptions.assign', 'subscriptions.update', 'subscriptions.cancel',
            'tenants.subscriptions.view', 'tenants.subscriptions.create', 'tenants.subscriptions.update', 'tenants.subscriptions.cancel',
            'billing.read', 'tenants.billing.view',
            'usage.read', 'tenants.usage.view',
            'admin.me', 'admin.logout',
        ];
        $billingAdminRole = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'Billing Admin')->orWhere('slug', 'billing_admin'))
            ->first();
        if ($billingAdminRole) {
            $billingAdminRole->syncPermissions(
                Permission::where('guard_name', 'web')->whereIn('name', $billingAdminPermissions)->get()
            );
        }

        // 5. Support Operator:
        // Dashboard, tenant read, plan read, subscription read, branding read, domains read, WhatsApp read, webhook read, integration read, usage read and monitoring read.
        $supportOperatorPermissions = [
            'dashboard.read',
            'tenants.read', 'tenants.view',
            'plans.read',
            'subscriptions.read', 'tenants.subscriptions.view',
            'branding.read',
            'domains.read',
            'whatsapp.read', 'whatsapp_accounts.view',
            'whatsapp.webhooks.read',
            'integrations.read',
            'usage.read', 'tenants.usage.view',
            'monitoring.read',
            'admin.me', 'admin.logout',
        ];
        $supportOperatorRole = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'Support Operator')->orWhere('slug', 'support_operator'))
            ->first();
        if ($supportOperatorRole) {
            $supportOperatorRole->syncPermissions(
                Permission::where('guard_name', 'web')->whereIn('name', $supportOperatorPermissions)->get()
            );
        }

        // 6. Auditor:
        // Dashboard read, tenant read, plan read, subscription read, billing read, WhatsApp read, webhook read, roles read, audit read, usage read and monitoring read.
        $auditorPermissions = [
            'dashboard.read',
            'tenants.read', 'tenants.view',
            'plans.read',
            'subscriptions.read', 'tenants.subscriptions.view',
            'billing.read', 'tenants.billing.view',
            'whatsapp.read', 'whatsapp_accounts.view',
            'whatsapp.webhooks.read',
            'roles.read', 'roles.view', 'permissions.view', 'roles.permissions.view',
            'audit.read', 'system-activity.view',
            'usage.read', 'tenants.usage.view',
            'monitoring.read',
            'admin.me', 'admin.logout',
        ];
        $auditorRole = Role::where('guard_name', 'web')
            ->where(fn ($q) => $q->where('name', 'Auditor')->orWhere('slug', 'auditor'))
            ->first();
        if ($auditorRole) {
            $auditorRole->syncPermissions(
                Permission::where('guard_name', 'web')->whereIn('name', $auditorPermissions)->get()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
