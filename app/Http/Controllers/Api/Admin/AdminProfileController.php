<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    /**
     * View authenticated Super Admin profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => (new AdminUserResource($user))->resolve(),
        ]);
    }

    /**
     * Update authenticated Super Admin profile (PUT: full update, PATCH: partial update).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Disallow updating email through profile update endpoint
        if ($request->has('email')) {
            return response()->json([
                'success' => false,
                'message' => 'Email address cannot be updated via profile update. Please use the dedicated email change OTP verification flow.',
                'errors' => [
                    'email' => [
                        'Email address cannot be updated via profile update. Please use the dedicated email change OTP verification flow.',
                    ],
                ],
            ], 422);
        }

        $isPut = $request->isMethod('PUT');
        $ruleMode = $isPut ? 'required' : 'sometimes';

        $validated = $request->validate([
            'first_name' => [$ruleMode, 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone_number' => [
                $ruleMode,
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($user->id),
            ],
        ]);

        // Explicitly extract permitted fields to avoid mass assignment
        $dataToUpdate = [];
        if (array_key_exists('first_name', $validated)) {
            $dataToUpdate['first_name'] = $validated['first_name'];
        }
        if (array_key_exists('last_name', $validated)) {
            $dataToUpdate['last_name'] = $validated['last_name'];
        }
        if (array_key_exists('phone_number', $validated)) {
            $dataToUpdate['phone_number'] = $validated['phone_number'];
        }

        if (empty($dataToUpdate)) {
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data' => (new AdminUserResource($user))->resolve(),
            ]);
        }

        $oldProfile = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone_number' => $user->phone_number,
        ];

        DB::transaction(function () use ($user, $dataToUpdate) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->fill($dataToUpdate);
            $locked->save();
        });

        $user->refresh();

        $this->activityLogService->log(
            'profile.updated',
            'profile',
            'Admin profile updated successfully',
            $user,
            User::class,
            $user->id,
            [
                'fields_changed' => array_keys($dataToUpdate),
                'old' => array_intersect_key($oldProfile, $dataToUpdate),
                'new' => $dataToUpdate,
            ],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => (new AdminUserResource($user))->resolve(),
        ]);
    }
}
