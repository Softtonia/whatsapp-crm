<?php

namespace App\Services;

use App\Models\AdminMfaChallenge;
use App\Models\AdminMfaRecoveryCode;
use App\Models\AdminMfaSetting;
use App\Models\TenantActivityLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class AdminMfaService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
        $this->google2fa->setWindow(1); // 1 period (30s) tolerance
    }

    /**
     * Check if MFA is confirmed and enabled for user.
     */
    public function isMfaEnabled(User $user): bool
    {
        return AdminMfaSetting::query()
            ->where('user_id', $user->id)
            ->where('is_enabled', true)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * Enroll MFA: generate secret, persist encrypted at rest, return provisioning URI & QR.
     */
    public function enroll(User $user, Request $request): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        AdminMfaSetting::updateOrCreate(
            ['user_id' => $user->id],
            [
                'totp_secret_encrypted' => Crypt::encryptString($secret),
                'is_enabled' => false,
                'confirmed_at' => null,
            ]
        );

        $appName = config('app.name', 'WhatsApp CRM');
        $provisioningUri = $this->google2fa->getQRCodeUrl(
            $appName,
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($provisioningUri);

        $this->logActivity(
            $user,
            'admin.mfa_enrolled',
            'Super admin initiated MFA enrollment',
            $request
        );

        return [
            'secret' => $secret,
            'provisioning_uri' => $provisioningUri,
            'qr_code_svg' => 'data:image/svg+xml;base64,' . base64_encode($qrCodeSvg),
        ];
    }

    /**
     * Confirm MFA enrollment using a valid TOTP code. Generates recovery codes.
     */
    public function confirm(User $user, string $code, Request $request): array
    {
        $setting = AdminMfaSetting::query()->where('user_id', $user->id)->first();
        if (! $setting || ! $setting->totp_secret_encrypted) {
            return [
                'success' => false,
                'message' => 'MFA enrollment has not been initiated. Please enroll first.',
            ];
        }

        try {
            $secret = Crypt::decryptString($setting->totp_secret_encrypted);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Unable to read MFA configuration.',
            ];
        }

        $isValid = $this->google2fa->verifyKey($secret, $code);
        if (! $isValid) {
            return [
                'success' => false,
                'message' => 'The provided one-time password is invalid or expired.',
            ];
        }

        $setting->update([
            'is_enabled' => true,
            'confirmed_at' => now(),
        ]);

        // Generate 8 cryptographically secure recovery codes
        $recoveryCodes = $this->generateRecoveryCodes($user);

        $this->logActivity(
            $user,
            'admin.mfa_confirmed',
            'Super admin confirmed and activated MFA',
            $request
        );

        return [
            'success' => true,
            'message' => 'MFA has been successfully activated.',
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Create short-lived pending login challenge for MFA-enabled user.
     */
    public function createChallenge(User $user, Request $request): AdminMfaChallenge
    {
        // Invalidate prior unused challenges
        AdminMfaChallenge::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $token = (string) Str::uuid();

        $challenge = AdminMfaChallenge::create([
            'user_id' => $user->id,
            'challenge_token' => $token,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->logActivity(
            $user,
            'admin.mfa_challenge_created',
            'MFA challenge issued during login',
            $request
        );

        return $challenge;
    }

    /**
     * Verify login challenge using TOTP code or Recovery Code.
     */
    public function verifyChallenge(string $challengeToken, ?string $code, ?string $recoveryCode, Request $request): array
    {
        $challenge = AdminMfaChallenge::query()
            ->where('challenge_token', $challengeToken)
            ->first();

        if (! $challenge) {
            return [
                'success' => false,
                'status' => 404,
                'message' => 'The MFA challenge token is invalid.',
            ];
        }

        if ($challenge->isUsed()) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'The MFA challenge has already been used.',
            ];
        }

        if ($challenge->isExpired()) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'The MFA challenge has expired. Please log in again.',
            ];
        }

        if ($challenge->attempts >= 5) {
            return [
                'success' => false,
                'status' => 429,
                'message' => 'Maximum verification attempts exceeded. Please log in again.',
            ];
        }

        $challenge->increment('attempts');
        $user = $challenge->user;

        $setting = AdminMfaSetting::query()->where('user_id', $user->id)->first();
        if (! $setting || ! $setting->is_enabled || ! $setting->totp_secret_encrypted) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'MFA is not enabled for this account.',
            ];
        }

        $verified = false;
        $usedMethod = 'totp';

        if (! empty($code)) {
            try {
                $secret = Crypt::decryptString($setting->totp_secret_encrypted);
                $verified = (bool) $this->google2fa->verifyKey($secret, $code);
            } catch (\Throwable $e) {
                $verified = false;
            }
        } elseif (! empty($recoveryCode)) {
            $usedMethod = 'recovery_code';
            $verified = $this->consumeRecoveryCode($user, $recoveryCode);
        }

        if (! $verified) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'The provided MFA code is incorrect or expired.',
            ];
        }

        // Mark challenge as used
        $challenge->update(['used_at' => now()]);

        $this->logActivity(
            $user,
            'admin.mfa_verified',
            "Admin successfully verified MFA using {$usedMethod}",
            $request
        );

        return [
            'success' => true,
            'user' => $user,
        ];
    }

    /**
     * Disable MFA for user (requires reauthentication & valid TOTP or recovery code).
     */
    public function disable(User $user, ?string $code, ?string $recoveryCode, Request $request): array
    {
        $setting = AdminMfaSetting::query()->where('user_id', $user->id)->first();
        if (! $setting || ! $setting->is_enabled) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'MFA is not currently enabled for this account.',
            ];
        }

        $verified = false;
        if (! empty($code)) {
            try {
                $secret = Crypt::decryptString($setting->totp_secret_encrypted);
                $verified = (bool) $this->google2fa->verifyKey($secret, $code);
            } catch (\Throwable $e) {
                $verified = false;
            }
        } elseif (! empty($recoveryCode)) {
            $verified = $this->consumeRecoveryCode($user, $recoveryCode);
        }

        if (! $verified) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'The provided MFA code is incorrect.',
            ];
        }

        $setting->update([
            'is_enabled' => false,
            'confirmed_at' => null,
            'totp_secret_encrypted' => null,
        ]);

        // Invalidate recovery codes and challenges
        AdminMfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        AdminMfaChallenge::query()->where('user_id', $user->id)->delete();

        $this->logActivity(
            $user,
            'admin.mfa_disabled',
            'Super admin disabled MFA',
            $request
        );

        return [
            'success' => true,
            'status' => 200,
            'message' => 'MFA has been successfully disabled.',
        ];
    }

    /**
     * Generate 8 cryptographically secure recovery codes and store their hashes.
     */
    protected function generateRecoveryCodes(User $user): array
    {
        AdminMfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        $plainCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(Str::random(10));
            $formatted = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $plainCodes[] = $formatted;

            AdminMfaRecoveryCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($formatted),
                'used_at' => null,
            ]);
        }

        return $plainCodes;
    }

    /**
     * Consume a recovery code if valid and unused.
     */
    protected function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalizedCode = strtoupper(trim($code));
        $recoveryRecords = AdminMfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($recoveryRecords as $record) {
            if (Hash::check($normalizedCode, $record->code_hash)) {
                $record->update(['used_at' => now()]);
                return true;
            }
        }

        return false;
    }

    /**
     * Record an audit event in tenant_activity_logs without storing secrets.
     */
    public function logActivity(User $user, string $eventType, string $description, ?Request $request = null): void
    {
        try {
            TenantActivityLog::create([
                'tenant_id' => null,
                'actor_user_id' => $user->id,
                'actor_type' => 'user',
                'event_type' => $eventType,
                'description' => $description,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->header('User-Agent'),
            ]);

            app(ActivityLogService::class)->log(
                $eventType,
                'security',
                $description,
                $user,
                User::class,
                $user->id,
                [],
                $request
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
