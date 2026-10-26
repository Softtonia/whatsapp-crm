<?php

namespace App\Jobs;

use App\Mail\AdminPasswordResetOtpMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class SendAdminPasswordResetOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 30;


    public function __construct(
        public int $userId,
        public string $email,
        public string $encryptedOtp,
        public int $expiryMinutes = 10
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if (
            ! $user ||
            (! $user->hasRole('Super Admin') && ! $user->hasRole('super_admin')) ||
            $user->email !== $this->email
        ) {
            // Do not send reset OTP to a changed or ineligible account.
            return;
        }

        try {
            $otp = Crypt::decryptString($this->encryptedOtp);
        } catch (DecryptException $exception) {
            throw new RuntimeException(
                'Unable to decrypt the admin password reset OTP.',
                0,
                $exception
            );
        }

        $template = \App\Models\EmailTemplate::query()
            ->where('key', 'ADMIN_PASSWORD_RESET_OTP')
            ->where('is_active', true)
            ->first();

        if ($template) {
            /** @var \App\Services\EmailTemplateRenderer $renderer */
            $renderer = app(\App\Services\EmailTemplateRenderer::class);
            $placeholders = [
                'AdminName' => $user->full_name ?: $user->name ?: 'Administrator',
                'UserName' => $user->full_name ?: $user->name ?: 'Administrator',
                'Otp' => $otp,
                'ExpiryMinutes' => (string) $this->expiryMinutes,
            ];

            $subject = $renderer->render($template->subject, $placeholders, $template->key);
            $body = $renderer->render($template->body, $placeholders, $template->key);
            $type = $template->type?->value ?? (string) $template->type ?: 'html';

            $user->notify(new \App\Notifications\CampaignEmailNotification($subject, $body, $type));
            return;
        }

        Mail::to($this->email)->send(
            new AdminPasswordResetOtpMail(
                $otp,
                $this->expiryMinutes
            )
        );
    }
}
