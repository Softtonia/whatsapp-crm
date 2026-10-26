<?php

namespace App\Events;

class AdminPasswordResetOtpRequested
{
    public function __construct(
        public int $userId,
        public string $email,
        public string $encryptedOtp,
        public int $expiryMinutes = 10
    ) {}
}
