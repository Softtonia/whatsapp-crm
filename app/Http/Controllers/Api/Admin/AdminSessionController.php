<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSession;
use App\Services\AdminSessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSessionController extends Controller
{
    public function __construct(
        protected AdminSessionService $sessionService
    ) {}

    /**
     * List only sessions belonging to the authenticated admin.
     *
     * GET /api/auth/admin/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        // Update current session's last activity
        $this->sessionService->touchSession($user, $currentToken, $request);

        $sessions = AdminSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (AdminSession $session) use ($currentToken) {
                $payload = $this->sessionService->decodePayload($session->payload);
                $deviceLabel = $payload['device_label'] ?? $this->sessionService->parseDeviceLabel($session->user_agent);
                $tokenId = $payload['personal_access_token_id'] ?? null;
                $loginTime = $payload['created_at'] ?? ($session->last_activity ? Carbon::createFromTimestamp($session->last_activity)->toISOString() : null);

                return [
                    'id' => $session->id,
                    'session_id' => $session->id,
                    'device_label' => $deviceLabel,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'login_time' => $loginTime,
                    'last_activity_at' => $session->last_activity ? Carbon::createFromTimestamp($session->last_activity)->toISOString() : null,
                    'is_current' => $currentToken && $tokenId && (int) $currentToken->id === (int) $tokenId,
                ];
            });

        return response()->json([
            'message' => 'Admin active sessions retrieved successfully.',
            'data' => $sessions,
        ]);
    }

    /**
     * Revoke a specific session belonging to the authenticated admin.
     *
     * DELETE /api/auth/admin/sessions/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $session = AdminSession::query()
            ->where('id', $id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session not found.',
            ], 404);
        }

        // Reject attempts to revoke another user's session
        if ((int) $session->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'You are not authorized to revoke this session.',
            ], 403);
        }

        $this->sessionService->revokeSession($session, $request);

        return response()->json([
            'message' => 'Session revoked successfully.',
        ]);
    }
}
