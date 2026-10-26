<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\TenantWhatsAppAccount;
use App\Models\WhatsAppWebhookEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    /**
     * Run all system health checks.
     */
    public function getHealthReport(): array
    {
        $startTime = microtime(true);

        $dbCheck = $this->checkDatabase();
        $redisCheck = $this->checkRedis();
        $queueCheck = $this->checkQueue();
        $paymentCheck = $this->checkPaymentProvider();
        $whatsappCheck = $this->checkWhatsAppIntegration();

        $components = [
            'database' => $dbCheck,
            'redis' => $redisCheck,
            'queue' => $queueCheck,
            'payment_provider' => $paymentCheck,
            'whatsapp' => $whatsappCheck,
        ];

        // Overall status calculation
        $criticalStatuses = ['unavailable'];
        $degradedStatuses = ['degraded'];

        $overallStatus = 'healthy';
        foreach ($components as $key => $check) {
            $status = $check['status'] ?? 'unknown';

            // Redis, Payment, and WhatsApp being not_configured should not make core system degraded
            if ($status === 'not_configured' || $status === 'unknown') {
                continue;
            }

            if (in_array($status, $criticalStatuses, true)) {
                // If core dependencies (database or queue) are unavailable, overall is unavailable
                if (in_array($key, ['database', 'queue'], true)) {
                    $overallStatus = 'unavailable';
                    break;
                }
                $overallStatus = 'degraded';
            } elseif (in_array($status, $degradedStatuses, true) && $overallStatus !== 'unavailable') {
                $overallStatus = 'degraded';
            }
        }

        $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);

        // Record check event in operational alerts if degraded or unavailable
        $this->recordHealthAlertIfNeeded($overallStatus, $components);

        return [
            'status' => $overallStatus,
            'checked_at' => now()->toIso8601String(),
            'duration_ms' => $totalDurationMs,
            'components' => $components,
        ];
    }

    /**
     * Check application liveness (process alive).
     */
    public function checkLiveness(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Check application readiness (ready to serve traffic).
     * Evaluates core dependencies required for basic traffic: database.
     */
    public function checkReadiness(): array
    {
        $dbCheck = $this->checkDatabase();
        $isReady = $dbCheck['status'] === 'healthy';

        return [
            'status' => $isReady ? 'ready' : 'not_ready',
            'timestamp' => now()->toIso8601String(),
            'database' => $dbCheck['status'],
        ];
    }

    /**
     * Check database connectivity.
     */
    public function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->select('SELECT 1');
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'name' => 'Database',
                'driver' => config('database.default'),
                'duration_ms' => $durationMs,
                'message' => 'Database connectivity established.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'unavailable',
                'name' => 'Database',
                'driver' => config('database.default'),
                'duration_ms' => $durationMs,
                'message' => 'Database connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
                'last_checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Check Redis connectivity.
     */
    public function checkRedis(): array
    {
        $redisHost = config('database.redis.default.host');
        $queueConnection = config('queue.default');
        $sessionDriver = config('session.driver');
        $cacheDriver = config('cache.default');

        $isRedisUsed = ($queueConnection === 'redis' || $sessionDriver === 'redis' || $cacheDriver === 'redis' || ! empty(env('REDIS_HOST')));

        if (! $isRedisUsed && empty($redisHost)) {
            return [
                'status' => 'not_configured',
                'name' => 'Redis',
                'message' => 'Redis is not configured or in use.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        }

        $start = microtime(true);
        try {
            Redis::connection()->ping();
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'name' => 'Redis',
                'duration_ms' => $durationMs,
                'message' => 'Redis connection is responsive.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'unavailable',
                'name' => 'Redis',
                'duration_ms' => $durationMs,
                'message' => 'Redis connection failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
                'last_checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Check Queue and Worker health.
     */
    public function checkQueue(): array
    {
        $driver = config('queue.default');
        $start = microtime(true);

        try {
            $pendingJobsCount = 0;
            $failedJobsCount = 0;

            if (Schema::hasTable('failed_jobs')) {
                $failedJobsCount = DB::table('failed_jobs')->count();
            }

            if ($driver === 'database' && Schema::hasTable('jobs')) {
                $pendingJobsCount = DB::table('jobs')->count();
            }

            // Check worker heartbeat or scheduler heartbeat
            $lastSchedulerRun = Cache::get('tenant_health_scheduler_last_run');
            $workerActive = Cache::get('queue_worker_heartbeat') !== null;

            $durationMs = round((microtime(true) - $start) * 1000, 2);

            // If queue driver is redis, ensure redis ping succeeds
            if ($driver === 'redis') {
                Redis::connection()->ping();
            }

            $status = 'healthy';
            $message = 'Queue is operational.';

            if ($failedJobsCount > 10) {
                $status = 'degraded';
                $message = "Queue has {$failedJobsCount} failed jobs requiring attention.";
            }

            return [
                'status' => $status,
                'name' => 'Queue Worker',
                'driver' => $driver,
                'duration_ms' => $durationMs,
                'pending_jobs' => $pendingJobsCount,
                'failed_jobs' => $failedJobsCount,
                'message' => $message,
                'last_checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'unavailable',
                'name' => 'Queue Worker',
                'driver' => $driver,
                'duration_ms' => $durationMs,
                'message' => 'Queue check failed: ' . $this->sanitizeErrorMessage($e->getMessage()),
                'last_checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Check payment provider (Razorpay) connectivity safely.
     */
    public function checkPaymentProvider(): array
    {
        $settingService = app(PlatformSettingService::class);
        $keyId = $settingService->get('razorpay_key_id') ?: config('services.razorpay.key');
        $keySecret = $settingService->get('razorpay_key_secret') ?: config('services.razorpay.secret');

        if (empty($keyId) || empty($keySecret)) {
            return [
                'status' => 'not_configured',
                'name' => 'Payment Provider (Razorpay)',
                'provider' => 'razorpay',
                'message' => 'Razorpay API credentials are not configured.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        }

        $start = microtime(true);
        try {
            // Lightweight connectivity ping with strict timeout (3 seconds)
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->timeout(3)
                ->get('https://api.razorpay.com/v1/payments?count=1');

            $durationMs = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                return [
                    'status' => 'healthy',
                    'name' => 'Payment Provider (Razorpay)',
                    'provider' => 'razorpay',
                    'duration_ms' => $durationMs,
                    'message' => 'Razorpay API connection verified.',
                    'last_checked_at' => now()->toIso8601String(),
                ];
            }

            return [
                'status' => 'degraded',
                'name' => 'Payment Provider (Razorpay)',
                'provider' => 'razorpay',
                'duration_ms' => $durationMs,
                'message' => 'Razorpay API returned error: ' . ($response->json('error.description') ?? 'Authentication failed.'),
                'last_checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'unavailable',
                'name' => 'Payment Provider (Razorpay)',
                'provider' => 'razorpay',
                'duration_ms' => $durationMs,
                'message' => 'Razorpay API unreachable: ' . $this->sanitizeErrorMessage($e->getMessage()),
                'last_checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Check Meta / WhatsApp integration connectivity safely.
     */
    public function checkWhatsAppIntegration(): array
    {
        $settingService = app(PlatformSettingService::class);
        $appSecret = $settingService->get('whatsapp_app_secret') ?: config('services.whatsapp.app_secret');
        $verifyToken = $settingService->get('whatsapp_verify_token') ?: config('services.whatsapp.verify_token');

        if (empty($appSecret) && empty($verifyToken)) {
            return [
                'status' => 'not_configured',
                'name' => 'WhatsApp Cloud API',
                'message' => 'WhatsApp integration credentials are not configured.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        }

        $totalAccounts = TenantWhatsAppAccount::count();
        $failedWebhooks = WhatsAppWebhookEvent::where('processing_status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $status = 'healthy';
        $message = 'WhatsApp integration is configured and active.';

        if ($failedWebhooks > 10) {
            $status = 'degraded';
            $message = "WhatsApp integration has {$failedWebhooks} failed webhook events in the last 24 hours.";
        }

        return [
            'status' => $status,
            'name' => 'WhatsApp Cloud API',
            'configured_accounts' => $totalAccounts,
            'recent_failed_webhooks' => $failedWebhooks,
            'message' => $message,
            'last_checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Record operational alerts with deduplication window (avoids creating alerts on every poll).
     */
    protected function recordHealthAlertIfNeeded(string $overallStatus, array $components): void
    {
        if ($overallStatus === 'healthy') {
            return;
        }

        $cacheKey = "health_alert_dedup_{$overallStatus}";
        if (Cache::has($cacheKey)) {
            return;
        }

        // Cache for 15 minutes to prevent spamming
        Cache::put($cacheKey, true, now()->addMinutes(15));

        $activityLog = app(ActivityLogService::class);
        $activityLog->log(
            eventType: 'system.health.alert',
            category: 'monitoring',
            description: "System health check reported {$overallStatus} status.",
            metadata: [
                'overall_status' => $overallStatus,
                'degraded_components' => array_keys(array_filter($components, fn ($c) => in_array($c['status'] ?? '', ['degraded', 'unavailable'], true))),
            ]
        );
    }

    /**
     * Sanitize error message to prevent leaking secrets, credentials, or file paths.
     */
    protected function sanitizeErrorMessage(string $error): string
    {
        // Remove file paths and stack traces
        $sanitized = preg_replace('/(\/[a-zA-Z0-9_\-\.]+)+/i', '[path]', $error);
        $sanitized = preg_replace('/([a-zA-Z]:\\\\[a-zA-Z0-9_\-\\\.]+)/i', '[path]', $sanitized);
        // Remove password=... or secret=...
        $sanitized = preg_replace('/(password|secret|key|token)=[^&\s]+/i', '$1=[redacted]', $sanitized);

        return substr($sanitized, 0, 255);
    }
}
