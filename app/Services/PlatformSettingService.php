<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\TenantWhatsAppAccount;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PlatformSettingService
{
    protected const CACHE_PREFIX = 'platform_setting:';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value with fallback.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            $setting = PlatformSetting::where('key', $key)->first();
            if ($setting) {
                return $setting->typed_value;
            }

            return $default;
        });
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string', bool $isSensitive = false): PlatformSetting
    {
        $setting = PlatformSetting::firstOrNew(['key' => $key]);
        $setting->group = $group;
        $setting->setTypedValue($value, $type, $isSensitive);
        $setting->save();

        Cache::forget(self::CACHE_PREFIX . $key);

        return $setting;
    }

    /**
     * Get all general platform settings.
     */
    public function getGeneralSettings(): array
    {
        return [
            'platform_name' => $this->get('platform_name', config('app.name', 'WhatsApp CRM')),
            'support_email' => $this->get('support_email', config('mail.from.address', 'support@whatsapp-crm.local')),
            'default_timezone' => $this->get('default_timezone', config('app.timezone', 'Asia/Kolkata')),
            'default_currency' => $this->get('default_currency', config('app.currency', 'INR')),
            'maintenance_mode' => (bool) $this->get('maintenance_mode', false),
        ];
    }

    /**
     * Update general settings with allowlisted partial update.
     */
    public function updateGeneralSettings(array $data): array
    {
        if (array_key_exists('platform_name', $data) && $data['platform_name'] !== null) {
            $this->set('platform_name', trim($data['platform_name']), 'general', 'string');
            config(['app.name' => trim($data['platform_name'])]);
        }

        if (array_key_exists('support_email', $data) && $data['support_email'] !== null) {
            $this->set('support_email', trim($data['support_email']), 'general', 'string');
            config(['mail.from.address' => trim($data['support_email'])]);
        }

        if (array_key_exists('default_timezone', $data) && $data['default_timezone'] !== null) {
            $tz = trim($data['default_timezone']);
            $this->set('default_timezone', $tz, 'general', 'string');
            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);
        }

        if (array_key_exists('default_currency', $data) && $data['default_currency'] !== null) {
            $curr = strtoupper(trim($data['default_currency']));
            $this->set('default_currency', $curr, 'general', 'string');
            config(['app.currency' => $curr]);
        }

        if (array_key_exists('maintenance_mode', $data) && $data['maintenance_mode'] !== null) {
            $this->set('maintenance_mode', (bool) $data['maintenance_mode'], 'general', 'boolean');
            config(['app.maintenance_mode' => (bool) $data['maintenance_mode']]);
        }

        return $this->getGeneralSettings();
    }

    /**
     * Get security settings.
     */
    public function getSecuritySettings(): array
    {
        return [
            'session_timeout_minutes' => (int) $this->get('session_timeout_minutes', config('session.lifetime', 120)),
            'mfa_enforced' => (bool) $this->get('mfa_enforced', false),
            'login_max_attempts' => (int) $this->get('login_max_attempts', 5),
            'login_decay_minutes' => (int) $this->get('login_decay_minutes', 1),
        ];
    }

    /**
     * Update security settings with allowlisted partial update.
     */
    public function updateSecuritySettings(array $data): array
    {
        if (array_key_exists('session_timeout_minutes', $data) && $data['session_timeout_minutes'] !== null) {
            $timeout = (int) $data['session_timeout_minutes'];
            $this->set('session_timeout_minutes', $timeout, 'security', 'integer');
            config(['session.lifetime' => $timeout]);
        }

        if (array_key_exists('mfa_enforced', $data) && $data['mfa_enforced'] !== null) {
            $this->set('mfa_enforced', (bool) $data['mfa_enforced'], 'security', 'boolean');
        }

        if (array_key_exists('login_max_attempts', $data) && $data['login_max_attempts'] !== null) {
            $this->set('login_max_attempts', (int) $data['login_max_attempts'], 'security', 'integer');
        }

        if (array_key_exists('login_decay_minutes', $data) && $data['login_decay_minutes'] !== null) {
            $this->set('login_decay_minutes', (int) $data['login_decay_minutes'], 'security', 'integer');
        }

        return $this->getSecuritySettings();
    }

    /**
     * Get safe summary of all implemented integrations.
     */
    public function getAllIntegrations(): array
    {
        return [
            'whatsapp' => $this->getWhatsAppIntegrationSummary(),
            'razorpay' => $this->getRazorpayIntegrationSummary(),
        ];
    }

    /**
     * Get safe WhatsApp/Meta integration summary.
     */
    public function getWhatsAppIntegrationSummary(): array
    {
        $appSecret = $this->get('whatsapp_app_secret') ?: config('services.whatsapp.app_secret');
        $verifyToken = $this->get('whatsapp_verify_token') ?: config('services.whatsapp.verify_token');
        $graphVersion = $this->get('whatsapp_graph_version') ?: config('services.whatsapp.graph_version', 'v23.0');

        $isConfigured = ! empty($appSecret) && ! empty($verifyToken);

        $totalEvents = class_exists(WhatsAppWebhookEvent::class) ? WhatsAppWebhookEvent::count() : 0;
        $failedEvents = class_exists(WhatsAppWebhookEvent::class) ? WhatsAppWebhookEvent::where('processing_status', 'failed')->count() : 0;
        $latestSuccessfulWebhook = class_exists(WhatsAppWebhookEvent::class)
            ? WhatsAppWebhookEvent::where('processing_status', 'processed')->latest('processed_at')->first()
            : null;
        $latestSyncedAccount = class_exists(TenantWhatsAppAccount::class) ? TenantWhatsAppAccount::whereNotNull('last_synced_at')->latest('last_synced_at')->first() : null;

        $lastVerifiedAt = $this->get('whatsapp_last_verified_at') ?: $latestSuccessfulWebhook?->processed_at?->toIso8601String();
        $isConnected = $isConfigured && (! empty($lastVerifiedAt) || $latestSyncedAccount !== null);

        return [
            'provider' => 'whatsapp',
            'name' => 'Meta WhatsApp Business API',
            'is_configured' => $isConfigured,
            'is_verified' => ! empty($lastVerifiedAt),
            'status' => $isConnected ? 'connected' : ($isConfigured ? 'configured' : 'unconfigured'),
            'environment' => 'production',
            'graph_version' => $graphVersion,
            'has_app_secret' => ! empty($appSecret),
            'has_verify_token' => ! empty($verifyToken),
            'webhook_url' => url('/api/v1/webhooks/meta/whatsapp'),
            'last_verification_at' => $lastVerifiedAt,
            'last_verification_result' => $isConnected ? 'success' : null,
            'last_sync_at' => $latestSyncedAccount?->last_synced_at?->toIso8601String(),
            'health' => $failedEvents > 0 && ($failedEvents / max(1, $totalEvents) > 0.2) ? 'degraded' : 'healthy',
        ];
    }

    /**
     * Get safe Razorpay integration summary.
     */
    public function getRazorpayIntegrationSummary(): array
    {
        $keyId = $this->get('razorpay_key_id') ?: config('services.razorpay.key');
        $keySecret = $this->get('razorpay_key_secret') ?: config('services.razorpay.secret');
        $webhookSecret = $this->get('razorpay_webhook_secret') ?: config('services.razorpay.webhook_secret');
        $mode = $this->get('razorpay_mode', 'test');
        $isActive = (bool) $this->get('razorpay_is_active', !empty($keyId) && !empty($keySecret));

        $lastTestedAt = $this->get('razorpay_last_tested_at');
        $lastTestStatus = $this->get('razorpay_last_test_status');
        $isConfigured = ! empty($keyId) && ! empty($keySecret);
        $isVerified = ($lastTestStatus === 'success');

        return [
            'provider' => 'razorpay',
            'name' => 'Razorpay Payment Gateway',
            'is_configured' => $isConfigured,
            'is_active' => $isActive,
            'is_verified' => $isVerified,
            'status' => $isVerified ? 'connected' : ($isConfigured ? 'configured' : 'unconfigured'),
            'mode' => $mode,
            'environment' => $mode,
            'key_id' => $keyId,
            'key_id_masked' => $this->maskString($keyId),
            'has_key_secret' => ! empty($keySecret),
            'has_webhook_secret' => ! empty($webhookSecret),
            'webhook_url' => url('/api/v1/webhooks/payments/razorpay'),
            'last_verification_at' => $lastTestedAt,
            'last_verification_result' => $lastTestStatus,
        ];
    }

    /**
     * Update provider-specific integration settings.
     */
    public function updateIntegration(string $provider, array $data): array
    {
        return match ($provider) {
            'whatsapp' => $this->updateWhatsAppIntegration($data),
            'razorpay' => $this->updateRazorpayIntegration($data),
            default => throw new \InvalidArgumentException("Unsupported integration provider [{$provider}]."),
        };
    }

    protected function updateWhatsAppIntegration(array $data): array
    {
        if (! empty($data['graph_version'])) {
            $this->set('whatsapp_graph_version', trim($data['graph_version']), 'integrations', 'string');
            config(['services.whatsapp.graph_version' => trim($data['graph_version'])]);
        }

        if (! empty($data['app_secret'])) {
            $this->set('whatsapp_app_secret', trim($data['app_secret']), 'integrations', 'encrypted', true);
            config(['services.whatsapp.app_secret' => trim($data['app_secret'])]);
        }

        if (! empty($data['verify_token'])) {
            $this->set('whatsapp_verify_token', trim($data['verify_token']), 'integrations', 'encrypted', true);
            config(['services.whatsapp.verify_token' => trim($data['verify_token'])]);
        }

        return $this->getWhatsAppIntegrationSummary();
    }

    protected function updateRazorpayIntegration(array $data): array
    {
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $this->set('razorpay_is_active', (bool) $data['is_active'], 'integrations', 'boolean');
        }

        if (! empty($data['mode'])) {
            $this->set('razorpay_mode', trim($data['mode']), 'integrations', 'string');
        }

        if (array_key_exists('key_id', $data) && $data['key_id'] !== null) {
            $this->set('razorpay_key_id', trim($data['key_id']), 'integrations', 'string');
        }

        // Only overwrite secret if a non-empty string was submitted
        if (! empty($data['key_secret'])) {
            $this->set('razorpay_key_secret', trim($data['key_secret']), 'integrations', 'encrypted', true);
        }

        if (! empty($data['webhook_secret'])) {
            $this->set('razorpay_webhook_secret', trim($data['webhook_secret']), 'integrations', 'encrypted', true);
        }

        return $this->getRazorpayIntegrationSummary();
    }

    /**
     * Get webhook signing secret dynamically for webhook verification.
     */
    public function getWebhookSecret(string $provider): ?string
    {
        if ($provider === 'razorpay') {
            return $this->get('razorpay_webhook_secret') ?: config('services.razorpay.webhook_secret');
        }

        if ($provider === 'whatsapp') {
            return $this->get('whatsapp_app_secret') ?: config('services.whatsapp.app_secret');
        }

        return null;
    }

    /**
     * Test live connectivity for a provider.
     */
    public function testConnection(string $provider, ?string $keyId = null, ?string $keySecret = null): array
    {
        if ($provider === 'razorpay') {
            $key = $keyId ?: ($this->get('razorpay_key_id') ?: config('services.razorpay.key'));
            $secret = $keySecret ?: ($this->get('razorpay_key_secret') ?: config('services.razorpay.secret'));

            if (empty($key) || empty($secret)) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Key ID or Key Secret is missing. Cannot test connection.',
                ];
            }

            try {
                $response = Http::withBasicAuth($key, $secret)
                    ->timeout(8)
                    ->get('https://api.razorpay.com/v1/payments?count=1');

                $isSuccess = $response->successful();
                $statusStr = $isSuccess ? 'success' : 'failed';
                $msg = $isSuccess ? 'Successfully connected to Razorpay API.' : ($response->json('error.description') ?? 'Authentication failed.');

                if ($isSuccess && empty($keyId) && empty($keySecret)) {
                    $this->set('razorpay_last_tested_at', now()->toIso8601String(), 'integrations', 'string');
                    $this->set('razorpay_last_test_status', $statusStr, 'integrations', 'string');
                }

                return [
                    'success' => $isSuccess,
                    'status' => $statusStr,
                    'message' => $msg,
                    'status_code' => $response->status(),
                ];
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Connection error: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'status' => 'configured',
            'message' => "Integration [{$provider}] is configured.",
        ];
    }

    protected function maskString(?string $str): ?string
    {
        if (empty($str)) {
            return null;
        }
        $len = strlen($str);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($str, 0, 4) . str_repeat('*', $len - 8) . substr($str, -4);
    }
}
