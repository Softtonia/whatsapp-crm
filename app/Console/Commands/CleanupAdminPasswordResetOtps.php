<?php

namespace App\Console\Commands;

use App\Models\AdminPasswordResetOtp;
use Illuminate\Console\Command;

class CleanupAdminPasswordResetOtps extends Command
{
    protected $signature = 'admin-password-otps:cleanup';

    protected $description = 'Delete expired and old admin password reset OTP records';

    public function handle(): int
    {
        $deleted = AdminPasswordResetOtp::query()
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhere(function ($verifiedQuery) {
                        $verifiedQuery
                            ->whereNotNull('verified_at')
                            ->where(
                                'verified_at',
                                '<',
                                now()->subDay()
                            );
                    });
            })
            ->delete();

        $this->info("Deleted {$deleted} expired OTP record(s).");

        return self::SUCCESS;
    }
}
