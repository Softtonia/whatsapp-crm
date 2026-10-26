<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminSecurityOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminChangeEmailController extends Controller
{
    public function __construct(
        protected AdminSecurityOtpService $otpService,
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * Step 1: Send verification OTP to the proposed new email address.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
        ]);

        $user = $request->user();
        $normalizedNewEmail = strtolower(trim($data['new_email']));

        if ($normalizedNewEmail === strtolower(trim($user->email))) {
            return response()->json([
                'success' => false,
                'message' => 'The new email address cannot be identical to your current email address.',
                'errors' => [
                    'new_email' => ['The new email address cannot be identical to your current email address.'],
                ],
            ], 422);
        }

        $result = $this->otpService->sendOtp(
            $user,
            'email_change',
            $normalizedNewEmail,
            $request
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }

    /**
     * Step 2: Verify the OTP sent to the new email address and receive authorization token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_email' => ['required', 'email', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $normalizedNewEmail = strtolower(trim($data['new_email']));

        $result = $this->otpService->verifyOtp(
            $user,
            'email_change',
            $data['otp'],
            $normalizedNewEmail,
            $request
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['status']);
    }

    /**
     * Step 3: Commit the email change inside a database transaction.
     */
    public function changeEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->id),
            ],
            'auth_token' => ['required', 'string', 'size:64'],
        ]);

        $user = $request->user();
        $normalizedNewEmail = strtolower(trim($data['new_email']));

        $oldEmail = $user->email;

        $committed = DB::transaction(function () use ($user, $normalizedNewEmail, $data) {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Recheck uniqueness right before update
            $exists = User::query()
                ->where('email', $normalizedNewEmail)
                ->where('id', '!=', $lockedUser->id)
                ->exists();

            if ($exists) {
                return false;
            }

            $consumedChallenge = $this->otpService->consumeAuthToken(
                $lockedUser,
                'email_change',
                $data['auth_token'],
                $normalizedNewEmail
            );

            if (! $consumedChallenge) {
                return false;
            }

            $lockedUser->email = $normalizedNewEmail;
            $lockedUser->email_verified_at = now();
            $lockedUser->save();

            return true;
        });

        if (! $committed) {
            $this->activityLogService->log(
                'email.change_failed',
                'security',
                'Email change failed: invalid authorization token or duplicate email',
                $user,
                User::class,
                $user->id,
                ['attempted_domain' => substr($normalizedNewEmail, strpos($normalizedNewEmail, '@'))],
                $request
            );

            return response()->json([
                'success' => false,
                'message' => 'The email change authorization token is invalid, expired, or has already been used.',
            ], 422);
        }

        $this->activityLogService->log(
            'email.changed',
            'security',
            'Super Admin email address updated and verified successfully',
            $user,
            User::class,
            $user->id,
            [
                'old_email_domain' => substr($oldEmail, strpos($oldEmail, '@')),
                'new_email_domain' => substr($normalizedNewEmail, strpos($normalizedNewEmail, '@')),
            ],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Email address updated and verified successfully.',
            'data' => [
                'email' => $normalizedNewEmail,
            ],
        ]);
    }
}
