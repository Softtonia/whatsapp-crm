<?php

namespace App\Services;

use App\Models\AdminSession;
use App\Models\TenantActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AdminSessionService
{
    /**
     * Parse user agent to generate a human-readable device label.
     */
    public function parseDeviceLabel(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown Device';
        }

        $browser = 'Unknown Browser';
        if (stripos($userAgent, 'Chrome') !== false && stripos($userAgent, 'Edg') === false) {
            $browser = 'Chrome';
        } elseif (stripos($userAgent, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($userAgent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($userAgent, 'PostmanRuntime') !== false) {
            $browser = 'Postman';
        }

        $platform = 'Unknown OS';
        if (stripos($userAgent, 'Windows') !== false) {
            $platform = 'Windows';
        } elseif (stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS') !== false) {
            $platform = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $platform = 'Linux';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $platform = 'iOS';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $platform = 'Android';
        }

        return "{$browser} on {$platform}";
    }

    /**
     * Create an admin session in the sessions table.
     */
    public function createSession(User $user, PersonalAccessToken $token, Request $request): AdminSession
    {
        $sessionId = (string) Str::uuid();
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');
        $deviceLabel = $this->parseDeviceLabel($userAgent);

        $payloadData = [
            'personal_access_token_id' => $token->id,
            'device_label' => $deviceLabel,
            'created_at' => now()->toISOString(),
        ];

        $session = AdminSession::create([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'payload' => base64_encode(json_encode($payloadData)),
            'last_activity' => time(),
        ]);

        $this->logActivity($user, 'admin.login', "Admin logged in from {$ip} ({$deviceLabel})", $request);

        return $session;
    }

    /**
     * Update session last activity time in sessions table.
     */
    public function touchSession(User $user, ?PersonalAccessToken $token, Request $request): void
    {
        if (! $token) {
            return;
        }

        $sessions = AdminSession::query()
            ->where('user_id', $user->id)
            ->get();

        foreach ($sessions as $session) {
            $payload = $this->decodePayload($session->payload);
            if (($payload['personal_access_token_id'] ?? null) == $token->id) {
                $session->update(['last_activity' => time()]);
                break;
            }
        }
    }

    /**
     * Revoke a specific session for an admin.
     */
    public function revokeSession(AdminSession $session, Request $request): void
    {
        $payload = $this->decodePayload($session->payload);
        $tokenId = $payload['personal_access_token_id'] ?? null;

        // Delete from sessions table
        $session->delete();

        if ($tokenId) {
            PersonalAccessToken::query()
                ->whereKey($tokenId)
                ->delete();
        }

        // Invalidate cached token if it belonged to this user
        Cache::forget('admin_auth_token_' . $session->user_id);

        $this->logActivity(
            $session->user,
            'admin.session_revoked',
            "Admin session {$session->id} was revoked",
            $request
        );
    }

    /**
     * Revoke all sessions for an admin (e.g. on password reset or full logout).
     */
    public function revokeAllSessions(User $user, ?Request $request = null): void
    {
        AdminSession::query()
            ->where('user_id', $user->id)
            ->delete();

        $user->tokens()->where('name', 'admin-auth-token')->delete();
        Cache::forget('admin_auth_token_' . $user->id);

        if ($request) {
            $this->logActivity(
                $user,
                'admin.all_sessions_revoked',
                'All admin sessions revoked for user',
                $request
            );
        }
    }

    /**
     * Decode session payload safely.
     */
    public function decodePayload(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        // Try base64 decoded json first
        $decoded = @base64_decode($payload, true);
        if ($decoded !== false) {
            $json = @json_decode($decoded, true);
            if (is_array($json)) {
                return $json;
            }
        }

        // Direct json decode fallback
        $direct = @json_decode($payload, true);
        if (is_array($direct)) {
            return $direct;
        }

        // Unserialize fallback for standard Laravel web session payloads
        try {
            $unserialized = @unserialize($payload);
            if (is_array($unserialized)) {
                return $unserialized;
            }
        } catch (\Throwable $e) {}

        return [];
    }

    /**
     * Record an audit event in tenant_activity_logs and activity_logs without storing secrets.
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
                'authentication',
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
