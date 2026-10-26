<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminChangeEmailController;
use App\Http\Controllers\Api\Admin\AdminChangePasswordController;
use App\Http\Controllers\Api\Admin\AdminContactController;
use App\Http\Controllers\Api\Admin\AdminLeadController;
use App\Http\Controllers\Api\Admin\AdminMfaController;
use App\Http\Controllers\Api\Admin\AdminProfileController;
use App\Http\Controllers\Api\Admin\AdminSessionController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AiReplyDraftController;
use App\Http\Controllers\Api\Admin\CampaignController;
use App\Http\Controllers\Api\Admin\ContactExportController;
use App\Http\Controllers\Api\Admin\ContactImportController;
use App\Http\Controllers\Api\Admin\EmailTemplateController;
use App\Http\Controllers\Api\Admin\InboxController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\PlatformSettingController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\RazorpayAdminConfigController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SubscriptionBillingController;
use App\Http\Controllers\Api\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\Admin\TenantAuditController;
use App\Http\Controllers\Api\Admin\TenantBrandingController;
use App\Http\Controllers\Api\Admin\TenantBulkOperationController;
use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\Admin\SystemMonitoringController;
use App\Http\Controllers\Api\Admin\TenantCustomDomainController;
use App\Http\Controllers\Api\Admin\TenantExportController;
use App\Http\Controllers\Api\Admin\TenantHealthCheckController;
use App\Http\Controllers\Api\Admin\TenantInternalNoteController;
use App\Http\Controllers\Api\Admin\TenantKnowledgeController;
use App\Http\Controllers\Api\Admin\TenantOffboardingController;
use App\Http\Controllers\Api\Admin\TenantOnboardingController;
use App\Http\Controllers\Api\Admin\TenantProvisioningController;
use App\Http\Controllers\Api\Admin\TenantSubscriptionController;
use App\Http\Controllers\Api\Admin\UsageMonitoringController;
use App\Http\Controllers\Api\Admin\WhatsAppAccountController;
use App\Http\Controllers\Api\Admin\WhatsAppAdminSettingsController;
use App\Http\Controllers\Api\Admin\WhatsAppAdminWabaController;
use App\Http\Controllers\Api\Admin\WhatsAppAdminWebhookController;
use App\Http\Controllers\Api\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Prefixed with /api/auth/admin. Dedicated to administrative tasks including
| authentication, platform users, RBAC, tenant lifecycle, WhatsApp CRM,
| billing, and security monitoring.
|
*/
Route::prefix('auth/admin')->name('admin.')->group(function () {

    // ------------------------------------------------------------------------
    // 1. Public Admin Authentication & Password Recovery
    // ------------------------------------------------------------------------
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,1')
        ->name('forgot-password');

    Route::post('/resend-password-otp', [AdminAuthController::class, 'resendPasswordOtp'])
        ->middleware('throttle:3,1')
        ->name('resend-password-otp');

    Route::post('/verify-password-otp', [AdminAuthController::class, 'verifyPasswordOtp'])
        ->middleware('throttle:5,1')
        ->name('verify-password-otp');

    Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('reset-password');

    // MFA Challenge Verification
    Route::post('/mfa/verify', [AdminMfaController::class, 'verify'])
        ->middleware('throttle:5,1')
        ->name('mfa.verify');

    // Admin Invitation Acceptance (Cryptographically signed token)
    Route::post('/users/accept-invitation', [AdminUserController::class, 'acceptInvitation'])
        ->middleware('throttle:10,1')
        ->name('users.accept-invitation');

    // ------------------------------------------------------------------------
    // 2. Authenticated Super Admin Account & Security Operations
    // ------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'super_admin'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])
            ->name('logout');

        // Profile Management
        Route::get('/profile', [AdminProfileController::class, 'show'])
            ->name('profile.show');
        Route::put('/profile', [AdminProfileController::class, 'update'])
            ->name('profile.update');
        Route::patch('/profile', [AdminProfileController::class, 'update'])
            ->name('profile.patch');

        // Activity Logs (Backward compatibility alias)
        Route::prefix('activity-logs')->name('activity-logs.')->group(function () {
            Route::get('/', [AdminActivityLogController::class, 'index'])->name('index');
            Route::get('/{id}', [AdminActivityLogController::class, 'show'])->whereNumber('id')->name('show');
        });

        // OTP-based Password Change
        Route::prefix('change-password')->name('change-password.')->group(function () {
            Route::post('/send-otp', [AdminChangePasswordController::class, 'sendOtp'])
                ->middleware('throttle:5,1')
                ->name('send-otp');
            Route::post('/verify-otp', [AdminChangePasswordController::class, 'verifyOtp'])
                ->middleware('throttle:10,1')
                ->name('verify-otp');
            Route::post('/', [AdminChangePasswordController::class, 'changePassword'])
                ->middleware('throttle:10,1')
                ->name('change');
        });

        // OTP-based Email Change
        Route::prefix('change-email')->name('change-email.')->group(function () {
            Route::post('/send-otp', [AdminChangeEmailController::class, 'sendOtp'])
                ->middleware('throttle:5,1')
                ->name('send-otp');
            Route::post('/verify-otp', [AdminChangeEmailController::class, 'verifyOtp'])
                ->middleware('throttle:10,1')
                ->name('verify-otp');
            Route::post('/', [AdminChangeEmailController::class, 'changeEmail'])
                ->middleware('throttle:5,1')
                ->name('change');
        });

        // Session Management
        Route::prefix('sessions')->name('sessions.')->group(function () {
            Route::get('/', [AdminSessionController::class, 'index'])
                ->name('index');
            Route::delete('/{id}', [AdminSessionController::class, 'destroy'])
                ->name('destroy');
        });

        // MFA Enrollment & Management
        Route::prefix('mfa')->name('mfa.')->group(function () {
            Route::post('/enroll', [AdminMfaController::class, 'enroll'])
                ->middleware('throttle:5,1')
                ->name('enroll');
            Route::post('/confirm', [AdminMfaController::class, 'confirm'])
                ->middleware('throttle:5,1')
                ->name('confirm');
            Route::post('/disable', [AdminMfaController::class, 'disable'])
                ->middleware('throttle:5,1')
                ->name('disable');
        });
    });

    // ------------------------------------------------------------------------
    // 3. RBAC & Platform Administration (Granular Permissions)
    // ------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'admin.access'])->group(function () {

        // Admin User Management
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');
            Route::post('/invite', [AdminUserController::class, 'invite'])->name('invite');
            Route::get('/{id}', [AdminUserController::class, 'show'])->whereNumber('id')->name('show');
            Route::patch('/{id}', [AdminUserController::class, 'update'])->whereNumber('id')->name('update');
            Route::post('/{id}/deactivate', [AdminUserController::class, 'deactivate'])->whereNumber('id')->name('deactivate');
            Route::post('/{id}/reactivate', [AdminUserController::class, 'reactivate'])->whereNumber('id')->name('reactivate');
        });

        // Role & Permission Management
        Route::apiResource('roles', RoleController::class)->names('roles');

        Route::prefix('permissions')->name('permissions.')->group(function () {
            Route::get('/', [PermissionController::class, 'index'])->name('index');
        });

        Route::get('/roles/{roleId}/permissions', [PermissionController::class, 'rolePermissions'])
            ->whereNumber('roleId')
            ->name('roles.permissions.show');

        Route::put('/roles/{roleId}/permissions', [PermissionController::class, 'syncRolePermissions'])
            ->whereNumber('roleId')
            ->name('roles.permissions.sync');

        // Security & Audit Logs
        Route::prefix('security')->name('security.')->group(function () {
            Route::get('/audit-logs', [AdminActivityLogController::class, 'index'])->name('audit-logs.index');
            Route::get('/audit-logs/{id}', [AdminActivityLogController::class, 'show'])->whereNumber('id')->name('audit-logs.show');
            Route::get('/login-activity', [AdminActivityLogController::class, 'loginActivity'])->name('login-activity.index');
        });

        // Platform Settings
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [PlatformSettingController::class, 'index'])->name('index');
            Route::patch('/general', [PlatformSettingController::class, 'updateGeneral'])->name('general.patch');
            Route::get('/integrations', [PlatformSettingController::class, 'integrations'])->name('integrations.index');
            Route::patch('/integrations/{provider}', [PlatformSettingController::class, 'updateIntegration'])->name('integrations.patch');
            Route::get('/security', [PlatformSettingController::class, 'security'])->name('security.index');
            Route::patch('/security', [PlatformSettingController::class, 'updateSecurity'])->name('security.patch');
        });

        // Third-Party Integrations & Gateway Settings
        Route::prefix('integrations')->name('integrations.')->group(function () {
            Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
                Route::get('/status', [WhatsAppAdminSettingsController::class, 'status'])->name('status');
                Route::patch('/config', [WhatsAppAdminSettingsController::class, 'updateConfig'])->name('config.update');
            });

            Route::prefix('razorpay')->name('razorpay.')->group(function () {
                Route::get('/config', [RazorpayAdminConfigController::class, 'show'])->name('config.show');
                Route::put('/config', [RazorpayAdminConfigController::class, 'update'])->name('config.update');
                Route::patch('/config', [RazorpayAdminConfigController::class, 'update'])->name('config.patch');
                Route::post('/test-connection', [RazorpayAdminConfigController::class, 'testConnection'])->name('test-connection');
                Route::post('/sync-webhook', [RazorpayAdminConfigController::class, 'syncWebhook'])->name('sync-webhook');
            });
        });

        // Platform-wide Usage Monitoring and Quotas
        Route::prefix('usage')->name('usage.')->group(function () {
            Route::get('/summary', [UsageMonitoringController::class, 'summary'])->name('summary');
            Route::get('/tenants', [UsageMonitoringController::class, 'tenants'])->name('tenants');
        });

        Route::prefix('tenants/{id}')->whereNumber('id')->name('tenants.usage-monitoring.')->group(function () {
            Route::get('/usage', [UsageMonitoringController::class, 'tenantUsage'])->name('usage');
            Route::get('/quotas', [UsageMonitoringController::class, 'tenantQuotas'])->name('quotas');
        });

        // System Health & Background Jobs Monitoring
        Route::prefix('monitoring')->name('monitoring.')->group(function () {
            Route::get('/health', [SystemMonitoringController::class, 'health'])->name('health');
            Route::get('/jobs', [SystemMonitoringController::class, 'jobs'])->name('jobs');
            Route::get('/jobs/{id}', [SystemMonitoringController::class, 'showJob'])->name('jobs.show');
            Route::post('/jobs/{id}/retry', [SystemMonitoringController::class, 'retryJob'])->name('jobs.retry');
        });
    });

    // ------------------------------------------------------------------------
    // 4. Sensitive Tenant & Platform Management (Super Admin Only)
    // ------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'super_admin'])->group(function () {

        // Tenant Management & Tenant Business Profiles
        Route::prefix('tenants')->name('tenants.')->group(function () {
            Route::get('/', [TenantController::class, 'index'])->name('index');
            Route::post('/', [TenantController::class, 'store'])->name('store');
            Route::get('/{tenant}', [TenantController::class, 'show'])->whereNumber('tenant')->name('show');
            Route::put('/{tenant}', [TenantController::class, 'update'])->whereNumber('tenant')->name('update');
            Route::patch('/{tenant}', [TenantController::class, 'update'])->whereNumber('tenant')->name('patch');
            Route::delete('/{tenant}', [TenantController::class, 'destroy'])->whereNumber('tenant')->name('destroy');

            // Offboarding & Onboarding
            Route::get('/{tenant}/offboarding/purge-preview', [TenantOffboardingController::class, 'purgePreview'])->whereNumber('tenant')->name('offboarding.purge-preview');
            Route::post('/{tenant}/offboarding/purge', [TenantOffboardingController::class, 'requestPurge'])->whereNumber('tenant')->name('offboarding.purge');
            Route::get('/{tenant}/onboarding', [TenantOnboardingController::class, 'show'])->whereNumber('tenant')->name('onboarding.show');
            Route::post('/{tenant}/onboarding', [TenantOnboardingController::class, 'update'])->whereNumber('tenant')->name('onboarding.update');

            // Lifecycle Actions
            Route::post('/{tenant}/activate', [TenantController::class, 'activate'])->whereNumber('tenant')->name('activate');
            Route::post('/{tenant}/suspend', [TenantController::class, 'suspend'])->whereNumber('tenant')->name('suspend');
            Route::post('/{tenant}/archive', [TenantController::class, 'archive'])->whereNumber('tenant')->name('archive');
            Route::post('/{tenant}/deactivate', [TenantController::class, 'deactivate'])->whereNumber('tenant')->name('deactivate');

            // Team Members & Ownership
            Route::get('/{tenant}/members', [TenantController::class, 'members'])->whereNumber('tenant')->name('members.index');
            Route::post('/{tenant}/members', [TenantController::class, 'addMember'])->whereNumber('tenant')->name('members.store');
            Route::patch('/{tenant}/members/{member}', [TenantController::class, 'updateMember'])->whereNumber('tenant')->whereNumber('member')->name('members.update');
            Route::delete('/{tenant}/members/{member}', [TenantController::class, 'removeMember'])->whereNumber('tenant')->whereNumber('member')->name('members.destroy');
            Route::post('/{tenant}/owner', [TenantController::class, 'transferOwner'])->whereNumber('tenant')->name('owner.transfer');

            // Auditing & Status History
            Route::get('/{tenant}/activity', [TenantAuditController::class, 'activity'])->whereNumber('tenant')->name('activity.index');
            Route::get('/{tenant}/status-history', [TenantAuditController::class, 'statusHistory'])->whereNumber('tenant')->name('status-history.index');

            // Internal Notes
            Route::get('/{tenant}/notes', [TenantInternalNoteController::class, 'index'])->whereNumber('tenant')->name('notes.index');
            Route::post('/{tenant}/notes', [TenantInternalNoteController::class, 'store'])->whereNumber('tenant')->name('notes.store');
            Route::patch('/{tenant}/notes/{note}', [TenantInternalNoteController::class, 'update'])->whereNumber('tenant')->whereNumber('note')->name('notes.update');
            Route::delete('/{tenant}/notes/{note}', [TenantInternalNoteController::class, 'destroy'])->whereNumber('tenant')->whereNumber('note')->name('notes.destroy');
            Route::patch('/{tenant}/notes/{note}/pin', [TenantInternalNoteController::class, 'togglePin'])->whereNumber('tenant')->whereNumber('note')->name('notes.pin');

            // White-Label Branding
            Route::get('/{tenant}/branding', [TenantBrandingController::class, 'show'])->whereNumber('tenant')->name('branding.show');
            Route::put('/{tenant}/branding', [TenantBrandingController::class, 'update'])->whereNumber('tenant')->name('branding.update');
            Route::post('/{tenant}/branding/assets', [TenantBrandingController::class, 'uploadAsset'])->whereNumber('tenant')->name('branding.assets.store');

            // Custom Domains
            Route::get('/{tenant}/domains', [TenantCustomDomainController::class, 'index'])->whereNumber('tenant')->name('domains.index');
            Route::post('/{tenant}/domains', [TenantCustomDomainController::class, 'store'])->whereNumber('tenant')->name('domains.store');
            Route::get('/{tenant}/domains/{domainId}', [TenantCustomDomainController::class, 'show'])->whereNumber('tenant')->name('domains.show');
            Route::post('/{tenant}/domains/{domainId}/verify', [TenantCustomDomainController::class, 'verify'])->whereNumber('tenant')->name('domains.verify');
            Route::post('/{tenant}/domains/{domainId}/provision-tls', [TenantCustomDomainController::class, 'provisionTls'])->whereNumber('tenant')->name('domains.provision-tls');
            Route::post('/{tenant}/domains/{domainId}/activate', [TenantCustomDomainController::class, 'activate'])->whereNumber('tenant')->name('domains.activate');
            Route::post('/{tenant}/domains/{domainId}/deactivate', [TenantCustomDomainController::class, 'deactivate'])->whereNumber('tenant')->name('domains.deactivate');
            Route::delete('/{tenant}/domains/{domainId}', [TenantCustomDomainController::class, 'destroy'])->whereNumber('tenant')->name('domains.destroy');
        });

        // Tenant Bulk Operations
        Route::prefix('tenant-bulk-operations')
            ->name('tenant-bulk-operations.')
            ->controller(TenantBulkOperationController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/{operation}', 'show')->whereNumber('operation')->name('show');
                Route::get('/{operation}/items', 'items')->whereNumber('operation')->name('items');
                Route::post('/{operation}/retry', 'retry')->whereNumber('operation')->name('retry');
                Route::post('/{operation}/cancel', 'cancel')->whereNumber('operation')->name('cancel');
            });

        // Tenant Exports
        Route::prefix('tenant-exports')
            ->name('tenant-exports.')
            ->controller(TenantExportController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/{export}/download', 'download')->whereNumber('export')->name('download');
                Route::get('/{export}', 'show')->whereNumber('export')->name('show');
            });

        // Tenant Health Checks
        Route::prefix('tenants/{tenant}/health-checks')
            ->name('tenants.health-checks.')
            ->whereNumber('tenant')
            ->controller(TenantHealthCheckController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('/run', 'run')->name('run');
                Route::get('/{healthCheck}', 'show')->whereNumber('healthCheck')->name('show');
                Route::patch('/{healthCheck}', 'update')->whereNumber('healthCheck')->name('update');
            });

        // Tenant Offboarding Queue & Purge
        Route::prefix('tenant-offboarding')
            ->name('tenant-offboarding.')
            ->controller(TenantOffboardingController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/{job}', 'show')->whereNumber('job')->name('show');
                Route::post('/{job}/approve', 'approve')->whereNumber('job')->name('approve');
                Route::post('/{job}/reject', 'reject')->whereNumber('job')->name('reject');
                Route::post('/{job}/cancel', 'cancel')->whereNumber('job')->name('cancel');
                Route::post('/{job}/retry', 'retry')->whereNumber('job')->name('retry');
                Route::post('/{job}/purge-approve', 'approvePurge')->whereNumber('job')->name('purge-approve');
                Route::post('/{job}/purge-reject', 'rejectPurge')->whereNumber('job')->name('purge-reject');
            });

        // WhatsApp Account Management
        Route::prefix('inbox/accounts')->name('inbox.accounts.')->group(function () {
            Route::get('/', [WhatsAppAccountController::class, 'index'])->name('index');
            Route::post('/', [WhatsAppAccountController::class, 'store'])->name('store');
            Route::patch('/{account}', [WhatsAppAccountController::class, 'update'])->whereNumber('account')->name('update');
        });

        // WhatsApp Inbox & Conversations
        Route::prefix('inbox')->name('inbox.')->group(function () {
            Route::get('/conversations', [InboxController::class, 'index'])->name('conversations.index');
            Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
            Route::get('/conversations/{conversation}/messages', [InboxController::class, 'messages'])->whereNumber('conversation')->name('conversations.messages');
            Route::patch('/conversations/{conversation}', [InboxController::class, 'update'])->whereNumber('conversation')->name('conversations.update');
            Route::post('/conversations/{conversation}/read', [InboxController::class, 'markRead'])->whereNumber('conversation')->name('conversations.read');
            Route::post('/conversations/{conversation}/notes', [InboxController::class, 'addNote'])->whereNumber('conversation')->name('conversations.notes');
            Route::post('/conversations/{conversation}/messages', [InboxController::class, 'sendMessage'])->whereNumber('conversation')->name('conversations.send-message');
            Route::get('/messages/{message}/media', [InboxController::class, 'media'])->whereNumber('message')->name('messages.media');
        });

        // Contact Management
        Route::prefix('contacts')->name('contacts.')->group(function () {
            Route::get('/', [AdminContactController::class, 'index'])->name('index');
            Route::post('/', [AdminContactController::class, 'store'])->name('store');
            Route::post('/import', ContactImportController::class)->name('import');
            Route::get('/export', [ContactExportController::class, 'export'])->name('export');
            Route::get('/import/template', [ContactExportController::class, 'template'])->name('import.template');
            Route::get('/{contact}', [AdminContactController::class, 'show'])->name('show');
            Route::patch('/{contact}', [AdminContactController::class, 'update'])->name('update');
            Route::delete('/{contact}', [AdminContactController::class, 'destroy'])->name('destroy');
        });

        // Lead Pipeline Stages & Leads
        Route::get('/lead-pipeline/stages', [AdminLeadController::class, 'pipelineStages'])->name('lead-pipeline.stages.index');
        Route::post('/lead-pipeline/stages', [AdminLeadController::class, 'createPipelineStage'])->name('lead-pipeline.stages.store');

        Route::prefix('leads')->name('leads.')->group(function () {
            Route::get('/', [AdminLeadController::class, 'index'])->name('index');
            Route::post('/', [AdminLeadController::class, 'store'])->name('store');
            Route::get('/{lead}', [AdminLeadController::class, 'show'])->name('show');
            Route::patch('/{lead}', [AdminLeadController::class, 'update'])->name('update');
            Route::post('/{lead}/notes', [AdminLeadController::class, 'addNote'])->name('notes.store');
        });

        // AI Knowledge Base & Draft Replies
        Route::prefix('ai')->name('ai.')->group(function () {
            Route::get('/knowledge', [TenantKnowledgeController::class, 'index'])->name('knowledge.index');
            Route::post('/knowledge', [TenantKnowledgeController::class, 'store'])->name('knowledge.store');
            Route::patch('/knowledge/{knowledge}', [TenantKnowledgeController::class, 'update'])->whereNumber('knowledge')->name('knowledge.update');
            Route::delete('/knowledge/{knowledge}', [TenantKnowledgeController::class, 'destroy'])->whereNumber('knowledge')->name('knowledge.destroy');
            Route::get('/drafts', [AiReplyDraftController::class, 'index'])->name('drafts.index');
            Route::patch('/drafts/{draft}', [AiReplyDraftController::class, 'update'])->whereNumber('draft')->name('drafts.update');
            Route::post('/drafts/{draft}/approve', [AiReplyDraftController::class, 'approve'])->whereNumber('draft')->name('drafts.approve');
            Route::post('/drafts/{draft}/reject', [AiReplyDraftController::class, 'reject'])->whereNumber('draft')->name('drafts.reject');
        });

        // Product Catalog Management
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('show');
            Route::patch('/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('update');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('destroy');
        });

        // Email Templates
        Route::prefix('email-templates')->name('email-templates.')->group(function () {
            Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
            Route::post('/', [EmailTemplateController::class, 'store'])->name('store');
            Route::post('/bulk-delete', [EmailTemplateController::class, 'bulkDestroy'])->name('bulk-delete');
            Route::delete('/bulk-delete', [EmailTemplateController::class, 'bulkDestroy']);
            Route::post('/preview', [EmailTemplateController::class, 'preview'])->name('preview');
            Route::get('/placeholders', [EmailTemplateController::class, 'placeholders'])->name('placeholders');
            Route::get('/by-key/{key}', [EmailTemplateController::class, 'byKey'])->name('by-key');
            Route::get('/{emailTemplate}', [EmailTemplateController::class, 'show'])->name('show');
            Route::put('/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('update');
            Route::delete('/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('destroy');
            Route::patch('/{emailTemplate}/status', [EmailTemplateController::class, 'updateStatus'])->name('update-status');
        });

        // Campaigns
        Route::prefix('campaigns')->name('campaigns.')->group(function () {
            Route::get('/events', [CampaignController::class, 'events'])->name('events');
            Route::get('/', [CampaignController::class, 'index'])->name('index');
            Route::post('/', [CampaignController::class, 'store'])->name('store');
            Route::post('/bulk-delete', [CampaignController::class, 'bulkDestroy'])->name('bulk-delete');
            Route::delete('/bulk-delete', [CampaignController::class, 'bulkDestroy']);
            Route::get('/{campaign}', [CampaignController::class, 'show'])->name('show');
            Route::put('/{campaign}', [CampaignController::class, 'update'])->name('update');
            Route::delete('/{campaign}', [CampaignController::class, 'destroy'])->name('destroy');
            Route::patch('/{campaign}/status', [CampaignController::class, 'updateStatus'])->name('update-status');
        });
    });

    // ------------------------------------------------------------------------
    // 5. Operational Platform Administration (RBAC Permission Protected)
    // ------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'admin.access'])->group(function () {

        // Subscription Plans CRUD
        Route::prefix('subscriptions/plans')->name('subscriptions.plans.')->group(function () {
            Route::get('/', [SubscriptionPlanController::class, 'index'])->name('index');
            Route::post('/', [SubscriptionPlanController::class, 'store'])->name('store');
            Route::get('/{plan}', [SubscriptionPlanController::class, 'show'])->whereNumber('plan')->name('show');
            Route::put('/{plan}', [SubscriptionPlanController::class, 'update'])->whereNumber('plan')->name('update');
            Route::patch('/{plan}', [SubscriptionPlanController::class, 'update'])->whereNumber('plan')->name('patch');
            Route::delete('/{plan}', [SubscriptionPlanController::class, 'destroy'])->whereNumber('plan')->name('destroy');
            Route::post('/{plan}/activate', [SubscriptionPlanController::class, 'activate'])->whereNumber('plan')->name('activate');
            Route::post('/{plan}/deactivate', [SubscriptionPlanController::class, 'deactivate'])->whereNumber('plan')->name('deactivate');
            Route::get('/{plan}/archive-preview', [SubscriptionPlanController::class, 'archivePreview'])->whereNumber('plan')->name('archive-preview');
            Route::post('/{plan}/archive', [SubscriptionPlanController::class, 'archive'])->whereNumber('plan')->name('archive');
            Route::get('/features', [SubscriptionPlanController::class, 'features'])->name('features');
            Route::put('/{plan}/entitlements', [SubscriptionPlanController::class, 'updateEntitlements'])->whereNumber('plan')->name('update-entitlements');
        });

        // Tenant Subscriptions Management
        Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
            Route::get('/', [TenantSubscriptionController::class, 'index'])->name('index');
            Route::post('/{subscription}/change-plan', [TenantSubscriptionController::class, 'changePlan'])->whereNumber('subscription')->name('change-plan');
            Route::post('/{subscription}/cancel', [TenantSubscriptionController::class, 'cancelSubscription'])->whereNumber('subscription')->name('cancel');
            Route::post('/{subscription}/reactivate', [TenantSubscriptionController::class, 'reactivate'])->whereNumber('subscription')->name('reactivate');
            Route::get('/{subscription}/events', [TenantSubscriptionController::class, 'events'])->whereNumber('subscription')->name('events');
        });

        // Tenant-Scoped Subscription Assignment & Usage
        Route::prefix('tenants/{tenant}/subscription')->name('tenants.subscription.')->whereNumber('tenant')->group(function () {
            Route::get('/', [TenantSubscriptionController::class, 'show'])->name('show');
            Route::post('/', [TenantSubscriptionController::class, 'assign'])->name('assign');
            Route::put('/{subscription}', [TenantSubscriptionController::class, 'update'])->whereNumber('subscription')->name('update');
            Route::post('/{subscription}/cancel', [TenantSubscriptionController::class, 'cancel'])->whereNumber('subscription')->name('cancel');
        });

        // Invoices & Payments (Subscription Billing)
        Route::prefix('invoices')->name('invoices.')->group(function () {
            Route::get('/', [SubscriptionBillingController::class, 'invoices'])->name('index');
            Route::get('/{invoice}', [SubscriptionBillingController::class, 'showInvoice'])->whereNumber('invoice')->name('show');
            Route::get('/{invoice}/payments', [SubscriptionBillingController::class, 'invoicePayments'])->whereNumber('invoice')->name('payments');
            Route::get('/{invoice}/download', [SubscriptionBillingController::class, 'downloadInvoice'])->whereNumber('invoice')->name('download');
        });

        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [SubscriptionBillingController::class, 'payments'])->name('index');
            Route::get('/{payment}', [SubscriptionBillingController::class, 'showPayment'])->whereNumber('payment')->name('show');
        });

        // Tenant Provisioning Automation
        Route::prefix('tenants/{tenant}/provisioning')
            ->whereNumber('tenant')
            ->name('tenants.provisioning.')
            ->controller(TenantProvisioningController::class)
            ->group(function () {
                Route::post('/', 'start')->name('start');
                Route::get('/', 'show')->name('show');
                Route::post('/retry', 'retry')->name('retry');
            });

        // WhatsApp WABA Accounts, Phone Numbers & Webhook Monitoring
        Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
            Route::get('/accounts', [WhatsAppAdminWabaController::class, 'index'])->name('accounts.index');
            Route::get('/accounts/{id}', [WhatsAppAdminWabaController::class, 'show'])->name('accounts.show');
            Route::post('/accounts/{id}/sync', [WhatsAppAdminWabaController::class, 'sync'])->name('accounts.sync');
            Route::get('/numbers', [WhatsAppAdminWabaController::class, 'numbers'])->name('numbers');
            Route::get('/webhooks', [WhatsAppAdminWebhookController::class, 'index'])->name('webhooks.index');
            Route::get('/webhooks/{id}', [WhatsAppAdminWebhookController::class, 'show'])->name('webhooks.show');
            Route::post('/webhooks/{id}/retry', [WhatsAppAdminWebhookController::class, 'retry'])->name('webhooks.retry');
        });
    });
});

// ----------------------------------------------------------------------------
// 5. Public Webhooks (Meta WhatsApp & Payment Providers)
// ----------------------------------------------------------------------------
Route::prefix('v1/webhooks')->name('webhooks.')->group(function () {
    Route::get('/meta/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('meta.whatsapp.verify');
    Route::post('/meta/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('meta.whatsapp.receive');

    Route::post('/payments/{provider}', [PaymentWebhookController::class, 'handle'])
        ->where('provider', 'razorpay')
        ->middleware('throttle:60,1')
        ->name('payments.razorpay');
});

// ----------------------------------------------------------------------------
// 6. Infrastructure Probes (Liveness & Readiness)
// ----------------------------------------------------------------------------
Route::prefix('monitoring')->name('infrastructure.monitoring.')->group(function () {
    Route::get('/liveness', [SystemMonitoringController::class, 'liveness'])->name('liveness');
    Route::get('/readiness', [SystemMonitoringController::class, 'readiness'])->name('readiness');
});

