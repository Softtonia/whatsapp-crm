<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiryMinutes = 10
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Admin Password Reset OTP'
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '
                <div style="font-family:Arial,sans-serif;line-height:1.6">
                    <h2>Admin Password Reset</h2>
                    <p>Use this one-time password to reset your admin password:</p>
                    <div style="font-size:30px;font-weight:bold;letter-spacing:8px">'
                        . e($this->otp) .
                    '</div>
                    <p>This OTP expires in ' . $this->expiryMinutes . ' minutes.</p>
                    <p>If you did not request this, please ignore this email.</p>
                </div>
            '
        );
    }
}