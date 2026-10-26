<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminSecurityOtpService;
use App\Services\AdminSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminChangePasswordController extends Controller
{
    public function __construct(
        protected AdminSecurityOtpService $otpService,
        protected ActivityLogService $activityLogService,
        protected AdminSessionService $sessionService
    ) {}

    /**
     * Step 1: Send OTP to authenticated Super Admin's registered email.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        $result = $this->otpService->sendOtp(
            $user,
            'password_change',
            $user->email,
            $request
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }

    /**
     * Step 2: Verify OTP and receive single-use authorization token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        $result = $this->otpService->verifyOtp(
            $user,
            'password_change',
            $request->input('otp'),
            $user->email,
            $request
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }

    /**
     * Step 3: Change password using the verified single-use authorization token.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auth_token' => ['required', 'string', 'size:64'],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $user = $request->user();

        // Prevent setting the same password as the current password
        if (Hash::check($data['new_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The new password cannot be the same as your current password. Please choose a different password.',
                'errors' => [
                    'new_password' => [
                        'The new password cannot be the same as your current password. Please choose a different password.',
                    ],
                ],
            ], 422);
        }

        $challenge = DB::transaction(function () use ($user, $data) {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $consumedChallenge = $this->otpService->consumeAuthToken(
                $lockedUser,
                'password_change',
                $data['auth_token'],
                $lockedUser->email
            );

            if (! $consumedChallenge) {
                return null;
            }

            $lockedUser->password = Hash::make($data['new_password']);
            $lockedUser->save();

            return $consumedChallenge;
        });

        if (! $challenge) {
            $this->activityLogService->log(
                'password.change_failed',
                'security',
                'Password change failed: invalid or expired authorization token',
                $user,
                User::class,
                $user->id,
                ['reason' => 'invalid_or_consumed_token'],
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'The password change authorization token is invalid, expired, or has already been used.',
            ], 422);
        }

        // Revoke other active personal access tokens/sessions per security policy, preserving current session
        try {
            $currentTokenId = $request->user()->currentAccessToken()?->id;
            if ($currentTokenId) {
                $user->tokens()->where('id', '!=', $currentTokenId)->delete();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $this->activityLogService->log(
            'password.changed',
            'security',
            'Super Admin password changed successfully using verified OTP authorization',
            $user,
            User::class,
            $user->id,
            [],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
