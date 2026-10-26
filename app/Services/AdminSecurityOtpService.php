<?php

namespace App\Services;

use App\Mail\AdminSecurityOtpMail;
use App\Models\AdminSecurityChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class AdminSecurityOtpService
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * Issue an OTP for a specified purpose ('password_change' or 'email_change').
     *
     * @return array{success: bool, status: int, message: string, data?: array}
     */
    public function sendOtp(User $user, string $purpose, string $targetEmail, ?Request $request = null): array
    {
        $normalizedEmail = strtolower(trim($targetEmail));

        // Lock user's outstanding challenges for this purpose
        $result = DB::transaction(function () use ($user, $purpose, $normalizedEmail, $request) {
            $activeChallenge = AdminSecurityChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            // Check 60-second cooldown
            if ($activeChallenge && $activeChallenge->last_sent_at && $activeChallenge->last_sent_at->gt(now()->subSeconds(60))) {
                return [
                    'success' => false,
                    'status' => 429,
                    'message' => 'Please wait 60 seconds before requesting another OTP.',
                ];
            }

            // Check resend limit
            if ($activeChallenge && $activeChallenge->resend_count >= 3) {
                $this->activityLogService->log(
                    "{$purpose}.otp_rate_limited",
                    'security',
                    "OTP resend limit exceeded for {$purpose}",
                    $user,
                    null,
                    null,
                    ['purpose' => $purpose],
                    $request
                );

                return [
                    'success' => false,
                    'status' => 429,
                    'message' => 'You have reached the maximum OTP resend limit. Please wait for the current OTP to expire.',
                ];
            }

            // Invalidate previous outstanding unconsumed challenges
            AdminSecurityChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Generate cryptographically secure 6-digit OTP
            $otp = (string) random_int(100000, 999999);
            $expiryMinutes = 10;

            $challenge = AdminSecurityChallenge::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'target_email' => $normalizedEmail,
                'otp_hash' => Hash::make($otp),
                'otp_encrypted' => Crypt::encryptString($otp),
                'expires_at' => now()->addMinutes($expiryMinutes),
                'last_sent_at' => now(),
                'resend_count' => $activeChallenge ? ($activeChallenge->resend_count + 1) : 0,
            ]);

            return [
                'success' => true,
                'status' => 200,
                'challenge' => $challenge,
                'raw_otp' => $otp,
                'expires_in' => $expiryMinutes * 60,
            ];
        });

        if (! $result['success']) {
            return $result;
        }

        // Dispatch email notification
        try {
            Mail::to($normalizedEmail)->send(
                new AdminSecurityOtpMail(
                    $result['raw_otp'],
                    $purpose,
                    10
                )
            );
        } catch (\Throwable $e) {
            report($e);
            // Delete challenge on complete mail failure so user isn't stuck
            $result['challenge']->delete();

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Unable to send OTP email. Please try again later.',
            ];
        }

        $this->activityLogService->log(
            "{$purpose}.otp_requested",
            'security',
            "OTP requested for {$purpose}",
            $user,
            null,
            null,
            ['purpose' => $purpose, 'target_email_domain' => substr($normalizedEmail, strpos($normalizedEmail, '@'))],
            $request
        );

        $msg = match ($purpose) {
            'email_change' => 'Email verification OTP sent to the new email address.',
            'security_settings_update' => 'Security settings verification OTP sent to your registered email address.',
            default => 'Password change OTP sent to your registered email address.',
        };

        return [
            'success' => true,
            'status' => 200,
            'message' => $msg,
            'data' => [
                'expires_in' => $result['expires_in'],
            ],
        ];
    }

    /**
     * Verify an OTP and generate a short-lived single-use authorization token.
     *
     * @return array{success: bool, status: int, message: string, data?: array}
     */
    public function verifyOtp(User $user, string $purpose, string $otp, ?string $targetEmail = null, ?Request $request = null): array
    {
        return DB::transaction(function () use ($user, $purpose, $otp, $targetEmail, $request) {
            $query = AdminSecurityChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate();

            if ($targetEmail) {
                $query->where('target_email', strtolower(trim($targetEmail)));
            }

            $challenge = $query->first();

            if (! $challenge) {
                $this->activityLogService->log(
                    "{$purpose}.otp_failed",
                    'security',
                    "OTP verification failed: no active challenge for {$purpose}",
                    $user,
                    null,
                    null,
                    ['reason' => 'expired_or_not_found'],
                    $request
                );

                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'The OTP is invalid or has expired.',
                ];
            }

            if ($challenge->attempts >= 5) {
                $challenge->update(['consumed_at' => now()]);

                $this->activityLogService->log(
                    "{$purpose}.otp_max_attempts",
                    'security',
                    "Maximum OTP verification attempts reached for {$purpose}",
                    $user,
                    null,
                    null,
                    ['purpose' => $purpose],
                    $request
                );

                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'The maximum verification attempts have been reached. Please request a new OTP.',
                ];
            }

            if (! Hash::check($otp, $challenge->otp_hash)) {
                $challenge->increment('attempts');

                $this->activityLogService->log(
                    "{$purpose}.otp_incorrect",
                    'security',
                    "Incorrect OTP attempt for {$purpose}",
                    $user,
                    null,
                    null,
                    ['attempt' => $challenge->attempts],
                    $request
                );

                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'The OTP is invalid or has expired.',
                ];
            }

            // Generate single-use authorization token (64 hex characters)
            $rawAuthToken = Str::random(64);

            $challenge->update([
                'verified_at' => now(),
                'auth_token_hash' => Hash::make($rawAuthToken),
                'auth_token_expires_at' => now()->addMinutes(10),
                'otp_encrypted' => null, // clear encrypted OTP once verified
            ]);

            $this->activityLogService->log(
                "{$purpose}.otp_verified",
                'security',
                "OTP verified successfully for {$purpose}",
                $user,
                null,
                null,
                ['purpose' => $purpose],
                $request
            );

            $msg = match ($purpose) {
                'email_change' => 'Email OTP verified successfully. You can now confirm the email change.',
                'security_settings_update' => 'Security OTP verified successfully. You can now update security settings.',
                default => 'OTP verified successfully. You can now change your password.',
            };

            return [
                'success' => true,
                'status' => 200,
                'message' => $msg,
                'data' => [
                    'auth_token' => $rawAuthToken,
                    'expires_in' => 600,
                ],
            ];
        });
    }

    /**
     * Validate and atomically consume the authorization token for completing the action.
     *
     * @return AdminSecurityChallenge|null
     */
    public function consumeAuthToken(User $user, string $purpose, string $rawAuthToken, ?string $expectedEmail = null): ?AdminSecurityChallenge
    {
        $query = AdminSecurityChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->whereNotNull('auth_token_hash')
            ->whereNull('consumed_at')
            ->where('auth_token_expires_at', '>', now())
            ->latest('id')
            ->lockForUpdate();

        if ($expectedEmail) {
            $query->where('target_email', strtolower(trim($expectedEmail)));
        }

        $challenge = $query->first();

        if (! $challenge) {
            return null;
        }

        if (! Hash::check($rawAuthToken, $challenge->auth_token_hash)) {
            return null;
        }

        // Atomically mark consumed
        $challenge->update([
            'consumed_at' => now(),
            'auth_token_hash' => null, // immediately destroy hash to prevent replay
        ]);

        return $challenge;
    }
}
