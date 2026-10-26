<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class TenantHealthCheckService
{
    public function runForTenant(Tenant $tenant): array
    {
        $results = [
            $this->saveCheck($tenant, 'database', 'infrastructure',
                fn () => $this->checkDatabase()),

            $this->saveCheck($tenant, 'tenant_profile', 'configuration',
                fn () => $this->checkTenantProfile($tenant)),

            $this->saveCheck($tenant, 'whatsapp_accounts', 'whatsapp',
                fn () => $this->checkWhatsAppAccounts($tenant)),

            $this->saveCheck($tenant, 'subscription', 'billing',
                fn () => $this->checkSubscription($tenant)),

            $this->saveCheck($tenant, 'queue', 'infrastructure',
                fn () => $this->checkQueue()),

            $this->saveCheck($tenant, 'scheduler', 'infrastructure',
                fn () => $this->checkScheduler()),
        ];

        return [
            'tenant_id' => $tenant->id,
            'overall_status' => $this->overallStatus($results),
            'checks' => $results,
        ];
    }

    private function saveCheck(
        Tenant $tenant,
        string $key,
        string $category,
        callable $callback
    ): array {
        try {
            $result = $callback();
        } catch (Throwable $e) {
            report($e);

            $result = [
                'status' => 'critical',
                'message' => 'Health check failed unexpectedly.',
                'details' => ['error_type' => class_basename($e)],
            ];
        }

        $check = $tenant->healthChecks()->updateOrCreate(
            ['check_key' => $key],
            [
                'category' => $category,
                'status' => $result['status'],
                'message' => $result['message'],
                'details' => $result['details'] ?? [],
                'checked_at' => now(),
                'next_check_at' => now()->addMinutes(5),
            ]
        );

        return [
            'check_key' => $check->check_key,
            'category' => $check->category,
            'status' => $check->status,
            'message' => $check->message,
            'checked_at' => $check->checked_at,
            'next_check_at' => $check->next_check_at,
        ];
    }

    private function checkDatabase(): array
    {
        DB::connection()->select('SELECT 1');

        return [
            'status' => 'healthy',
            'message' => 'Database connection is working.',
            'details' => [
                'connection' => config('database.default'),
            ],
        ];
    }

    private function checkTenantProfile(Tenant $tenant): array
    {
        $required = ['company_name', 'slug'];
        $missing = [];

        foreach ($required as $field) {
            if (blank($tenant->{$field})) {
                $missing[] = $field;
            }
        }

        if ($missing) {
            return [
                'status' => 'warning',
                'message' => 'Tenant profile is incomplete.',
                'details' => ['missing_fields' => $missing],
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'Required tenant profile fields are present.',
            'details' => [],
        ];
    }

    private function checkWhatsAppAccounts(Tenant $tenant): array
    {
        $accounts = $tenant->whatsappAccounts()->get();

        if ($accounts->isEmpty()) {
            return [
                'status' => 'warning',
                'message' => 'No WhatsApp accounts are configured.',
                'details' => ['account_count' => 0],
            ];
        }

        $connected = 0;
        $disconnected = 0;
        $unknown = 0;

        foreach ($accounts as $account) {
            $attributes = $account->getAttributes();
            $state = null;

            foreach (['is_connected', 'connected'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $state = filter_var(
                        $attributes[$field],
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    );
                    break;
                }
            }

            if ($state === true) {
                $connected++;
                continue;
            }

            if ($state === false) {
                $disconnected++;
                continue;
            }

            foreach (['connection_status', 'status'] as $field) {
                if (!isset($attributes[$field])) {
                    continue;
                }

                $value = strtolower((string) $attributes[$field]);

                if (in_array($value, ['connected', 'online', 'ready'], true)) {
                    $connected++;
                } elseif (in_array(
                    $value,
                    ['disconnected', 'offline', 'failed', 'error'],
                    true
                )) {
                    $disconnected++;
                } else {
                    $unknown++;
                }

                continue 2;
            }

            $unknown++;
        }

        if ($connected === $accounts->count()) {
            $status = 'healthy';
            $message = 'All WhatsApp accounts report a connected state.';
        } elseif ($disconnected > 0 && $connected === 0 && $unknown === 0) {
            $status = 'critical';
            $message = 'All WhatsApp accounts report a disconnected or failed state.';
        } elseif ($connected > 0 || $disconnected > 0) {
            $status = 'warning';
            $message = 'Some WhatsApp account states need attention.';
        } else {
            $status = 'unknown';
            $message = 'Account records exist, but their connection state could not be determined.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'details' => [
                'account_count' => $accounts->count(),
                'connected_count' => $connected,
                'disconnected_count' => $disconnected,
                'unknown_count' => $unknown,
            ],
        ];
    }

    private function checkSubscription(Tenant $tenant): array
    {
        $subscriptions = $tenant->subscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return [
                'status' => 'warning',
                'message' => 'No subscription record was found.',
                'details' => [],
            ];
        }

        $active = false;
        $expired = false;
        $unknown = false;

        foreach ($subscriptions as $subscription) {
            $attributes = $subscription->getAttributes();
            $state = strtolower((string) ($attributes['status'] ?? ''));

            $expiryField = null;

            foreach ([
                'ends_at',
                'expires_at',
                'current_period_end',
                'trial_ends_at',
            ] as $field) {
                if (!empty($attributes[$field])) {
                    $expiryField = $field;
                    break;
                }
            }

            if (in_array($state, ['active', 'trialing'], true)) {
                if ($expiryField && Carbon::parse($attributes[$expiryField])->isPast()) {
                    $expired = true;
                } else {
                    $active = true;
                }
            } elseif (in_array(
                $state,
                ['expired', 'cancelled', 'canceled', 'suspended', 'inactive'],
                true
            )) {
                $expired = true;
            } else {
                $unknown = true;
            }
        }

        if ($active) {
            return [
                'status' => 'healthy',
                'message' => 'An active subscription record was found.',
                'details' => [],
            ];
        }

        if ($expired && !$unknown) {
            return [
                'status' => 'critical',
                'message' => 'No active subscription was found.',
                'details' => [],
            ];
        }

        return [
            'status' => 'unknown',
            'message' => 'Subscription status could not be confirmed.',
            'details' => [],
        ];
    }

    private function checkQueue(): array
    {
        $driver = config('queue.default');

        if ($driver === 'sync') {
            return [
                'status' => 'warning',
                'message' => 'Queue uses the synchronous driver; background processing is not enabled.',
                'details' => ['driver' => $driver],
            ];
        }

        if ($driver === 'redis') {
            Redis::connection()->ping();
        }

        return [
            'status' => 'healthy',
            'message' => 'Queue driver configuration is available.',
            'details' => ['driver' => $driver],
        ];
    }

    private function checkScheduler(): array
    {
        $lastRun = Cache::get('tenant_health_scheduler_last_run');

        if (!$lastRun) {
            return [
                'status' => 'unknown',
                'message' => 'No scheduler heartbeat has been recorded yet.',
                'details' => [],
            ];
        }

        $lastRun = Carbon::parse($lastRun);
        $ageMinutes = (int) $lastRun->diffInMinutes(now());

        if ($ageMinutes > 10) {
            return [
                'status' => 'critical',
                'message' => 'Scheduler heartbeat is older than 10 minutes.',
                'details' => [
                    'last_run_at' => $lastRun->toDateTimeString(),
                    'age_minutes' => $ageMinutes,
                ],
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'Scheduler heartbeat is recent.',
            'details' => ['last_run_at' => $lastRun->toDateTimeString()],
        ];
    }

    private function overallStatus(array $results): string
    {
        $statuses = array_column($results, 'status');

        if (in_array('critical', $statuses, true)) {
            return 'critical';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        if (in_array('unknown', $statuses, true)) {
            return 'unknown';
        }

        return 'healthy';
    }
}