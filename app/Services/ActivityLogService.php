<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class ActivityLogService
{
    /**
     * Record an audit event strictly into the dedicated activity_logs table.
     * Never writes to tenant_activity_logs.
     */
    public function log(
        string $eventType,
        string $category,
        string $description,
        ?User $actor = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
        ?Request $request = null,
        ?int $tenantId = null,
        string $status = 'success',
        ?array $oldValues = null,
        ?array $newValues = null
    ): ?ActivityLog {
        try {
            $req = $request ?? (request() instanceof Request ? request() : null);

            // Correlation ID resolution: prioritize request attribute set by CorrelationIdMiddleware
            $requestId = $req?->attributes->get('request_id')
                ?? $req?->header('X-Request-ID')
                ?? $req?->header('X-Correlation-ID');

            // Strip sensitive fields defensively
            $safeMetadata = $this->sanitizeMetadata($metadata);
            $safeOldValues = $oldValues !== null ? $this->sanitizeMetadata($oldValues) : null;
            $safeNewValues = $newValues !== null ? $this->sanitizeMetadata($newValues) : null;

            $actorType = null;
            if ($actor) {
                if ($actor->hasRole('platform_owner') || $actor->roles()->where('slug', 'platform_owner')->exists()) {
                    $actorType = 'platform_owner';
                } elseif ($actor->hasRole('Super Admin') || $actor->hasRole('super_admin')) {
                    $actorType = 'super_admin';
                } else {
                    $actorType = 'admin';
                }
            }

            return ActivityLog::create([
                'actor_user_id' => $actor?->id,
                'actor_type' => $actorType,
                'event_type' => $eventType,
                'category' => $category,
                'description' => $description,
                'subject_type' => $subjectType ?? ($actor ? User::class : null),
                'subject_id' => $subjectId ?? $actor?->id,
                'ip_address' => $req?->ip(),
                'user_agent' => $req?->header('User-Agent'),
                'request_id' => $requestId,
                'tenant_id' => $tenantId,
                'status' => $status,
                'metadata' => empty($safeMetadata) ? null : $safeMetadata,
                'old_values' => empty($safeOldValues) ? null : $safeOldValues,
                'new_values' => empty($safeNewValues) ? null : $safeNewValues,
            ]);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Defense-in-depth: Recursively redacts passwords, tokens, credentials, and secrets.
     */
    public function sanitizeMetadata(array $data): array
    {
        $prohibitedPatterns = [
            'password',
            'password_confirmation',
            'current_password',
            'old_password',
            'new_password',
            'otp',
            'totp',
            'token',
            'secret',
            'recovery_code',
            'auth_token',
            'authorization',
            'cookie',
            'session_id',
            'private_key',
            'api_key',
            'webhook_secret',
            'card_number',
            'cvv',
            'credit_card',
        ];

        $cleaned = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isProhibited = false;
            foreach ($prohibitedPatterns as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isProhibited = true;
                    break;
                }
            }

            if ($isProhibited) {
                $cleaned[$key] = '[REDACTED]';
            } else {
                if (is_array($value)) {
                    $cleaned[$key] = $this->sanitizeMetadata($value);
                } else {
                    $cleaned[$key] = $value;
                }
            }
        }

        return $cleaned;
    }
}
