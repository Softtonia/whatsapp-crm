<?php

namespace App\Listeners;

use App\Events\AdminPasswordResetOtpRequested;
use App\Jobs\SendAdminPasswordResetOtpJob;

class SendAdminPasswordResetOtp
{
    public function handle(
        AdminPasswordResetOtpRequested $event
    ): void {
        SendAdminPasswordResetOtpJob::dispatch(
            $event->userId,
            $event->email,
            $event->encryptedOtp,
            $event->expiryMinutes
        )->onQueue('emails');
    }
}
