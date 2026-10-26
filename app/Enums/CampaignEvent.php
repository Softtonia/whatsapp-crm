<?php

namespace App\Enums;

enum CampaignEvent: string
{
    case FORGOT_PASSWORD = 'forgot_password';
    case RESET_PASSWORD = 'reset_password';
    case OTP_VERIFICATION = 'otp_verification';

    public function label(): string
    {
        return match ($this) {
            self::FORGOT_PASSWORD => 'Forgot Password',
            self::RESET_PASSWORD => 'Reset Password',
            self::OTP_VERIFICATION => 'OTP Verification',
        };
    }

    /**
     * Get all supported events as an array of value/label objects for API responses.
     */
    public static function forApi(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
