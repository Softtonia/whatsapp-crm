<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminSecurityOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $purpose, // 'password_change' or 'email_change'
        public int $expiryMinutes = 10
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->purpose === 'email_change'
            ? 'Verify Your New Email Address'
            : 'Super Admin Password Change OTP';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $title = $this->purpose === 'email_change'
            ? 'Email Address Verification'
            : 'Password Change Request';

        $instruction = $this->purpose === 'email_change'
            ? 'Use this one-time password to verify and link your new email address:'
            : 'Use this one-time password to authorize changing your password:';

        return new Content(
            htmlString: '
                <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:560px;margin:auto;padding:20px;border:1px solid #e2e8f0;border-radius:8px">
                    <h2 style="color:#1a202c">' . e($title) . '</h2>
                    <p>' . e($instruction) . '</p>
                    <div style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#f7fafc;padding:16px;text-align:center;border-radius:6px;margin:20px 0;color:#2d3748">'
                        . e($this->otp) .
                    '</div>
                    <p style="color:#718096;font-size:14px">This OTP will expire in ' . $this->expiryMinutes . ' minutes. If you did not request this, please secure your account immediately.</p>
                </div>
            '
        );
    }
}
