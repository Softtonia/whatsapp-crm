<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\AdminPasswordResetOtpRequested;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminPasswordResetOtp;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\ActivityLogService;
use App\Services\AdminMfaService;
use App\Services\AdminSessionService;
use Throwable;

class AdminAuthController extends Controller
{
    public function __construct(
        protected AdminMfaService $mfaService,
        protected AdminSessionService $sessionService,
        protected ActivityLogService $activityLogService
    ) {}
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // Dynamic Rate Limiting backed by PlatformSettingService
        $settingService = app(\App\Services\PlatformSettingService::class);
        $maxAttempts = (int) $settingService->get('login_max_attempts', 5);
        $decayMinutes = (int) $settingService->get('login_decay_minutes', 1);
        $throttleKey = 'admin_login:' . Str::lower((string) $data['email']) . '|' . $request->ip();

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Too many login attempts. Please try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($throttleKey, $decayMinutes * 60);

            $this->activityLogService->log(
                eventType: 'auth.login_failed',
                category: 'authentication',
                description: "Failed login attempt for [{$data['email']}]: Invalid credentials.",
                actor: $user,
                subjectType: User::class,
                subjectId: $user?->id,
                metadata: [
                    'attempted_email' => $data['email'],
                    'failure_reason' => 'invalid_credentials',
                ],
                request: $request,
                status: 'failure'
            );

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Credentials are valid; clear throttle attempts
        \Illuminate\Support\Facades\RateLimiter::clear($throttleKey);

        if ($user->status && $user->status !== 'active') {
            $this->activityLogService->log(
                eventType: 'auth.login_blocked',
                category: 'security',
                description: "Blocked login attempt for deactivated user [{$user->email}].",
                actor: $user,
                subjectType: User::class,
                subjectId: $user->id,
                metadata: [
                    'status' => $user->status,
                    'failure_reason' => 'account_deactivated',
                ],
                request: $request,
                status: 'failure'
            );

            return response()->json([
                'message' => 'This account has been deactivated. Please contact an administrator.',
            ], 403);
        }

        $hasAdminRole = $user->roles()
            ->where('guard_name', 'web')
            ->where(function ($q) {
                $q->where('name', 'platform_owner')
                    ->orWhere('slug', 'platform_owner')
                    ->orWhere('name', 'Super Admin')
                    ->orWhere('name', 'super_admin')
                    ->orWhere('slug', 'super_admin')
                    ->orWhere('name', 'Administrator')
                    ->orWhere('slug', 'administrator');
            })
            ->exists();

        if (! $hasAdminRole) {
            return response()->json([
                'message' => 'This account is not authorized for admin access.',
            ], 403);
        }

        // MFA Evaluation:
        // 1. If user already has MFA enabled, require MFA challenge verification.
        if ($this->mfaService->isMfaEnabled($user)) {
            $challenge = $this->mfaService->createChallenge($user, $request);

            return response()->json([
                'message' => 'MFA verification required.',
                'mfa_required' => true,
                'challenge_token' => $challenge->challenge_token,
                'expires_at' => $challenge->expires_at->toISOString(),
            ], 200);
        }

        // 2. If platform policy enforces MFA globally and user hasn't enrolled yet:
        $isMfaGloballyEnforced = (bool) $settingService->get('mfa_enforced', false);
        if ($isMfaGloballyEnforced) {
            $enrollment = $this->mfaService->enroll($user, $request);

            return response()->json([
                'message' => 'MFA is enforced on this platform. Please complete MFA enrollment to proceed.',
                'mfa_required' => true,
                'mfa_enrollment_required' => true,
                'secret' => $enrollment['secret'],
                'provisioning_uri' => $enrollment['provisioning_uri'],
                'qr_code_svg' => $enrollment['qr_code_svg'],
            ], 200);
        }

        $role = $user->roles()->where('guard_name', 'web')->first();

        $cacheKey = 'admin_auth_token_' . $user->id;

        $result = DB::transaction(function () use ($user, $cacheKey, $request) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cachedToken = Cache::get($cacheKey);

            if (is_string($cachedToken) && $cachedToken !== '') {
                try {
                    $plainTextToken = Crypt::decryptString($cachedToken);
                    $parts = explode('|', $plainTextToken, 2);

                    if (
                        count($parts) === 2 &&
                        ctype_digit($parts[0])
                    ) {
                        $tokenRecord = $lockedUser->tokens()
                            ->whereKey((int) $parts[0])
                            ->where('name', 'admin-auth-token')
                            ->where('expires_at', '>', now())
                            ->first();

                        if (
                            $tokenRecord &&
                            hash_equals(
                                $tokenRecord->token,
                                hash('sha256', $parts[1])
                            )
                        ) {
                            $this->sessionService->createSession($lockedUser, $tokenRecord, $request);

                            return [
                                'token' => $plainTextToken,
                                'expires_in' => max(
                                    0,
                                    $tokenRecord->expires_at->timestamp
                                        - now()->timestamp
                                ),
                            ];
                        }
                    }
                } catch (DecryptException $exception) {
                    report($exception);
                }
            }

            $lockedUser->tokens()
                ->where('name', 'admin-auth-token')
                ->delete();

            $newToken = $lockedUser->createToken(
                'admin-auth-token',
                ['*'],
                now()->addHours(24)
            );

            $plainTextToken = $newToken->plainTextToken;

            Cache::put(
                $cacheKey,
                Crypt::encryptString($plainTextToken),
                now()->addHours(24)
            );

            $this->sessionService->createSession($lockedUser, $newToken->accessToken, $request);

            return [
                'token' => $plainTextToken,
                'expires_in' => 86400,
            ];
        });

        $this->activityLogService->log(
            eventType: 'auth.login_success',
            category: 'authentication',
            description: "Admin user [{$user->email}] logged in successfully.",
            actor: $user,
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'role' => $role?->name,
            ],
            request: $request,
            status: 'success'
        );

        return response()->json([
            'message' => 'Admin login successful.',
            'token' => $result['token'],
            // 'token_type' => 'Bearer',
            // 'expires_in' => $result['expires_in'],
            'role' => [
                'name' => $role->name,
            ],
        ]);
    }

    public function me(Request $request): AdminUserResource
    {
        return new AdminUserResource(
            $request->user()->load('roles')
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $request) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->sessionService->revokeAllSessions($lockedUser, $request);
        });

        return response()->json([
            'message' => 'Admin logged out successfully.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email address.',
            ], 404);
        }

        if (! $user->hasRole('Super Admin')) {
            return response()->json([
                'message' => 'This account is not eligible for password reset through the admin portal.',
            ], 403);
        }

        $otpData = DB::transaction(function () use ($user) {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeOtp = AdminPasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($activeOtp) {
                return null;
            }

            AdminPasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->delete();

            $otp = (string) random_int(100000, 999999);

            $record = AdminPasswordResetOtp::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'otp_hash' => Hash::make($otp),
                'otp_encrypted' => Crypt::encryptString($otp),
                'expires_at' => now()->addMinutes(10),
                'attempts' => 0,
                'resend_count' => 0,
            ]);

            return ['record' => $record];
        });

        if (! $otpData) {
            return response()->json([
                'message' => 'An OTP has already been issued. Please wait before requesting another OTP.',
            ], 429);
        }

        try {
            event(new AdminPasswordResetOtpRequested(
                userId: $user->id,
                email: $user->email,
                encryptedOtp: $otpData['record']->otp_encrypted,
                expiryMinutes: 10
            ));

            $otpData['record']->update([
                'last_sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $otpData['record']->delete();

            return response()->json([
                'message' => 'Unable to send the password reset email. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'Password reset email has been sent successfully to your registered email address.',
        ]);
    }

    public function resendPasswordOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this email address.',
            ], 404);
        }

        if (! $user->hasRole('Super Admin')) {
            return response()->json([
                'message' => 'This account is not eligible for password reset through the admin portal.',
            ], 403);
        }

        $record = AdminPasswordResetOtp::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record || ! $record->otp_encrypted) {
            return response()->json([
                'message' => 'No active OTP found. Please request a new password reset OTP.',
            ], 422);
        }

        if (
            $record->last_sent_at &&
            $record->last_sent_at->gt(now()->subSeconds(60))
        ) {
            return response()->json([
                'message' => 'Please wait 60 seconds before requesting another OTP.',
            ], 429);
        }

        if ($record->resend_count >= 3) {
            return response()->json([
                'message' => 'You have reached the maximum OTP resend limit. Please request a new OTP after the current OTP expires.',
            ], 429);
        }

        try {
            event(new AdminPasswordResetOtpRequested(
                userId: $user->id,
                email: $user->email,
                encryptedOtp: $record->otp_encrypted,
                expiryMinutes: 10
            ));

            $record->update([
                'last_sent_at' => now(),
                'resend_count' => $record->resend_count + 1,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to resend the password reset OTP. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'A new password reset email has been sent successfully.',
        ]);
    }

    public function verifyPasswordOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user || ! $user->hasRole('Super Admin')) {
            return response()->json([
                'message' => 'The OTP is invalid or expired.',
            ], 422);
        }

        $resetToken = DB::transaction(function () use ($user, $data) {
            $record = AdminPasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record || $record->attempts >= 5) {
                return null;
            }

            if (! Hash::check($data['otp'], $record->otp_hash)) {
                $record->increment('attempts');

                return null;
            }

            $token = Str::random(64);

            $record->update([
                'verified_at' => now(),
                'reset_token_hash' => Hash::make($token),
                'reset_token_expires_at' => now()->addMinutes(10),
                'otp_encrypted' => null,
            ]);

            return $token;
        });

        if (! $resetToken) {
            return response()->json([
                'message' => 'The OTP is invalid or expired, or the maximum verification attempts have been reached.',
            ], 422);
        }

        return response()->json([
            'message' => 'OTP verified successfully.',
            'reset_token' => $resetToken,
            'expires_in' => 600,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'The reset token is invalid or expired.',
            ], 422);
        }

        if (! $user->hasRole('Super Admin')) {
            return response()->json([
                'message' => 'This account is not eligible for password reset through the admin portal.',
            ], 403);
        }

        $success = DB::transaction(function () use ($user, $data) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (Hash::check($data['password'], $lockedUser->password)) {
                return 'same_as_current';
            }

            $record = AdminPasswordResetOtp::query()
                ->where('user_id', $lockedUser->id)
                ->whereNotNull('verified_at')
                ->whereNotNull('reset_token_hash')
                ->where('reset_token_expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (
                ! $record ||
                ! Hash::check(
                    $data['reset_token'],
                    $record->reset_token_hash
                )
            ) {
                return false;
            }

            $lockedUser->password = Hash::make($data['password']);
            $lockedUser->save();

            $this->sessionService->revokeAllSessions($lockedUser, $request);

            AdminPasswordResetOtp::query()
                ->where('user_id', $lockedUser->id)
                ->delete();

            return true;
        });

        if ($success === 'same_as_current') {
            return response()->json([
                'message' => 'The new password cannot be the same as your current password. Please choose a different password.',
                'errors' => [
                    'password' => [
                        'The new password cannot be the same as your current password. Please choose a different password.',
                    ],
                ],
            ], 422);
        }

        if (! $success) {
            return response()->json([
                'message' => 'The reset token is invalid or expired.',
            ], 422);
        }

        try {
            app(\App\Services\CampaignEmailService::class)->triggerEvent(
                \App\Enums\CampaignEvent::RESET_PASSWORD,
                $user,
                [
                    'UserName' => $user->full_name ?: $user->name ?: 'Administrator',
                    'name' => $user->full_name ?: $user->name ?: 'Administrator',
                    'email' => $user->email,
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Admin password reset successfully. Please log in again.',
        ]);
    }
}
