<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminMfaService;
use App\Services\AdminSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminMfaController extends Controller
{
    public function __construct(
        protected AdminMfaService $mfaService,
        protected AdminSessionService $sessionService
    ) {}

    /**
     * POST /api/auth/admin/mfa/enroll
     *
     * Super Admin enrolls MFA. Requires password re-authentication for security.
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided password does not match our records.',
            ], 422);
        }

        $enrollment = $this->mfaService->enroll($user, $request);

        return response()->json([
            'message' => 'MFA enrollment initiated. Scan the QR code or enter the secret in your authenticator app.',
            'secret' => $enrollment['secret'],
            'provisioning_uri' => $enrollment['provisioning_uri'],
            'qr_code_svg' => $enrollment['qr_code_svg'],
        ]);
    }

    /**
     * POST /api/auth/admin/mfa/confirm
     *
     * Confirms MFA using a valid TOTP code. Enables MFA and returns one-time recovery codes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:10'],
        ]);

        $user = $request->user();

        $result = $this->mfaService->confirm($user, $data['code'], $request);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'recovery_codes' => $result['recovery_codes'],
            'warning' => 'Save these recovery codes in a safe place. They will NOT be shown again.',
        ]);
    }

    /**
     * POST /api/auth/admin/mfa/verify
     *
     * Validates MFA challenge during login using TOTP code or recovery code.
     * On success, issues personal access token and creates an AdminSession.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'min:6', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'min:8', 'max:20'],
        ]);

        $result = $this->mfaService->verifyChallenge(
            $data['challenge_token'],
            $data['code'] ?? null,
            $data['recovery_code'] ?? null,
            $request
        );

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        /** @var User $user */
        $user = $result['user'];
        $role = $user->roles()->where('guard_name', 'web')->first();

        // Issue token and create session
        $cacheKey = 'admin_auth_token_' . $user->id;

        $tokenResult = DB::transaction(function () use ($user, $cacheKey, $request) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

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

            $session = $this->sessionService->createSession($lockedUser, $newToken->accessToken, $request);

            return [
                'token' => $plainTextToken,
                'session_id' => $session->session_id,
                'expires_in' => 86400,
            ];
        });

        return response()->json([
            'message' => 'Admin MFA verified successfully. Login complete.',
            'token' => $tokenResult['token'],
            'session_id' => $tokenResult['session_id'],
            'role' => [
                'name' => $role?->name ?? 'Super Admin',
            ],
        ]);
    }

    /**
     * POST /api/auth/admin/mfa/disable
     *
     * Disables MFA for authenticated Super Admin. Requires password and TOTP or recovery code.
     */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'min:6', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'min:8', 'max:20'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided password does not match our records.',
            ], 422);
        }

        $result = $this->mfaService->disable(
            $user,
            $data['code'] ?? null,
            $data['recovery_code'] ?? null,
            $request
        );

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}
