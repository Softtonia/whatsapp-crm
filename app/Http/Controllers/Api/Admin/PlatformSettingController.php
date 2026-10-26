<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\AdminSecurityOtpService;
use App\Services\PlatformSettingService;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformSettingController extends Controller
{
    public function __construct(
        protected PlatformSettingService $settingService,
        protected ActivityLogService $activityLogService,
        protected AdminSecurityOtpService $securityOtpService
    ) {}

    /**
     * GET /api/auth/admin/settings
     * Return safe, documented general settings.
     * Permission: settings.read
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'settings.read');

        return response()->json([
            'success' => true,
            'message' => 'Platform general settings retrieved successfully.',
            'data' => $this->settingService->getGeneralSettings(),
        ]);
    }

    /**
     * PATCH /api/auth/admin/settings/general
     * Allowlisted partial update for general platform settings.
     * Permission: settings.manage
     */
    public function updateGeneral(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'settings.manage');

        $supportedCurrencies = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'SGD'];
        $validTimezones = DateTimeZone::listIdentifiers();
        $allowedKeys = ['platform_name', 'support_email', 'default_timezone', 'default_currency', 'maintenance_mode'];

        // Explicitly reject unsupported/unknown fields (SET-002)
        $unknownKeys = array_diff(array_keys($request->all()), $allowedKeys);
        if (! empty($unknownKeys)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => array_fill_keys(array_values($unknownKeys), ['The field is unsupported or unrecognized.']),
            ], 422);
        }

        // Strict allowlist validation
        $data = $request->validate([
            'platform_name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'support_email' => ['sometimes', 'email', 'max:255'],
            'default_timezone' => ['sometimes', 'string', Rule::in($validTimezones)],
            'default_currency' => ['sometimes', 'string', Rule::in($supportedCurrencies)],
            'maintenance_mode' => ['sometimes', 'boolean'],
        ]);

        $before = $this->settingService->getGeneralSettings();
        $updated = $this->settingService->updateGeneralSettings($data);

        $this->activityLogService->log(
            eventType: 'platform.settings.general_updated',
            category: 'settings',
            description: 'Updated general platform settings',
            actor: $request->user(),
            metadata: [
                'updated_fields' => array_keys($data),
            ],
            request: $request,
            oldValues: $before,
            newValues: $updated
        );

        return response()->json([
            'success' => true,
            'message' => 'General platform settings updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * GET /api/auth/admin/settings/integrations
     * Safe summary of all implemented integrations without secrets.
     * Permission: integrations.read
     */
    public function integrations(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.read');

        return response()->json([
            'success' => true,
            'message' => 'Integration settings retrieved successfully.',
            'data' => $this->settingService->getAllIntegrations(),
        ]);
    }

    /**
     * PATCH /api/auth/admin/settings/integrations/{provider}
     * Update specific provider integration settings.
     * Permission: integrations.manage
     */
    public function updateIntegration(Request $request, string $provider): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'integrations.manage');

        $allowedProviders = ['whatsapp', 'razorpay'];
        if (! in_array(strtolower($provider), $allowedProviders, true)) {
            return response()->json([
                'success' => false,
                'message' => "Unsupported integration provider [{$provider}]. Allowed: " . implode(', ', $allowedProviders),
            ], 404);
        }

        $provider = strtolower($provider);

        $allowedFieldsByProvider = [
            'whatsapp' => ['graph_version', 'app_secret', 'verify_token'],
            'razorpay' => ['is_active', 'mode', 'key_id', 'key_secret', 'webhook_secret'],
        ];

        // Explicitly reject unsupported/unknown fields per provider (SET-002)
        $unknownKeys = array_diff(array_keys($request->all()), $allowedFieldsByProvider[$provider]);
        if (! empty($unknownKeys)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => array_fill_keys(array_values($unknownKeys), ["The field is unsupported for provider [{$provider}]."]),
            ], 422);
        }

        // Explicit allowlisted validation per provider
        $data = match ($provider) {
            'whatsapp' => $request->validate([
                'graph_version' => ['sometimes', 'string', 'max:15', 'regex:/^v\d+\.\d+$/'],
                'app_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
                'verify_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]),
            'razorpay' => $request->validate([
                'is_active' => ['sometimes', 'boolean'],
                'mode' => ['sometimes', 'string', 'in:test,live'],
                'key_id' => ['sometimes', 'nullable', 'string', 'max:100'],
                'key_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
                'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]),
        };

        $result = $this->settingService->updateIntegration($provider, $data);

        // Audit logging (secrets are automatically sanitized by activityLogService)
        $this->activityLogService->log(
            eventType: "platform.integration.{$provider}_updated",
            category: 'settings',
            description: "Updated {$provider} integration configuration",
            actor: $request->user(),
            metadata: [
                'provider' => $provider,
                'updated_fields' => array_keys($data),
            ],
            request: $request
        );

        return response()->json([
            'success' => true,
            'message' => ucfirst($provider) . ' integration configuration updated successfully.',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/auth/admin/settings/security
     * Return security policy configuration.
     * Permission: settings.read
     */
    public function security(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'settings.read');

        return response()->json([
            'success' => true,
            'message' => 'Security settings retrieved successfully.',
            'data' => $this->settingService->getSecuritySettings(),
        ]);
    }

    /**
     * PATCH /api/auth/admin/settings/security
     * Update security policies with strict bounds and verified reauthentication.
     * Permission: security.manage
     */
    public function updateSecurity(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'security.manage');

        $allowedSecurityKeys = [
            'session_timeout_minutes',
            'mfa_enforced',
            'login_max_attempts',
            'login_decay_minutes',
            'reauth_token',
            'current_password',
        ];

        // Explicitly reject unsupported/unknown fields (SET-002)
        $unknownKeys = array_diff(array_keys($request->all()), $allowedSecurityKeys);
        if (! empty($unknownKeys)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => array_fill_keys(array_values($unknownKeys), ['The field is unsupported or unrecognized.']),
            ], 422);
        }

        $data = $request->validate([
            'session_timeout_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440'], // 15 mins to 24 hours
            'mfa_enforced' => ['sometimes', 'boolean'],
            'login_max_attempts' => ['sometimes', 'integer', 'min:3', 'max:20'],
            'login_decay_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'reauth_token' => ['sometimes', 'nullable', 'string', 'max:128'],
            'current_password' => ['sometimes', 'nullable', 'string'],
        ]);

        // Reauthentication verification for high-risk mutations:
        // Support either valid single-use reauth_token or current_password verification.
        $user = $request->user();
        if (! empty($data['reauth_token'])) {
            $consumed = $this->securityOtpService->consumeAuthToken(
                $user,
                'security_settings_update',
                $data['reauth_token']
            );

            // Also check 'password_change' or general security challenge fallback
            if (! $consumed) {
                $consumed = $this->securityOtpService->consumeAuthToken(
                    $user,
                    'password_change',
                    $data['reauth_token']
                );
            }

            if (! $consumed) {
                $this->activityLogService->log(
                    eventType: 'platform.settings.security_update_rejected',
                    category: 'security',
                    description: 'Rejected security settings update: invalid or expired reauth_token',
                    actor: $user,
                    status: 'failure',
                    request: $request
                );

                return response()->json([
                    'success' => false,
                    'message' => 'The reauthentication token is invalid, expired, or has already been used.',
                    'errors' => [
                        'reauth_token' => ['The reauthentication token is invalid, expired, or has already been used.'],
                    ],
                ], 422);
            }
        } elseif (! empty($data['current_password'])) {
            if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
                $this->activityLogService->log(
                    eventType: 'platform.settings.security_update_rejected',
                    category: 'security',
                    description: 'Rejected security settings update: invalid password challenge',
                    actor: $user,
                    status: 'failure',
                    request: $request
                );

                return response()->json([
                    'success' => false,
                    'message' => 'The provided current password does not match.',
                    'errors' => [
                        'current_password' => ['The provided current password does not match.'],
                    ],
                ], 422);
            }
        }

        // Clean out auth challenge fields before applying to settings
        unset($data['reauth_token'], $data['current_password']);

        $before = $this->settingService->getSecuritySettings();
        $updated = $this->settingService->updateSecuritySettings($data);

        $this->activityLogService->log(
            eventType: 'platform.settings.security_updated',
            category: 'security',
            description: 'Updated platform security settings',
            actor: $request->user(),
            metadata: [
                'updated_fields' => array_keys($data),
            ],
            request: $request,
            oldValues: $before,
            newValues: $updated
        );

        return response()->json([
            'success' => true,
            'message' => 'Platform security settings updated successfully.',
            'data' => $updated,
        ]);
    }

    protected function authorizeAdmin($user, string $permission): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('platform_owner')
            || $user->hasRole('Super Admin')
            || $user->hasRole('super_admin');

        if (! $isSuperAdmin && ! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "You do not have the required permission: {$permission}.");
        }
    }
}
