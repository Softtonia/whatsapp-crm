<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class RazorpayConfigService
{
    public function __construct(
        protected PlatformSettingService $settingService
    ) {}

    /**
     * Get Razorpay configuration array with masked secrets.
     */
    public function getConfig(): array
    {
        return $this->settingService->getRazorpayIntegrationSummary();
    }

    /**
     * Update dynamic Razorpay configuration.
     */
    public function updateConfig(array $data): array
    {
        return $this->settingService->updateIntegration('razorpay', $data);
    }

    /**
     * Test connection to Razorpay API.
     */
    public function testConnection(?string $keyId = null, ?string $keySecret = null): array
    {
        return $this->settingService->testConnection('razorpay', $keyId, $keySecret);
    }
}
