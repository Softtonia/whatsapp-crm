<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AdminInvitation $invitation,
        public string $acceptUrl,
        public string $roleName,
        public string $expiryDate
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name', 'WhatsApp CRM');

        return new Envelope(
            subject: "Invitation to join {$appName} as {$this->roleName}"
        );
    }

    public function content(): Content
    {
        $appName = config('app.name', 'WhatsApp CRM');

        return new Content(
            htmlString: '
                <div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:560px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:8px;background:#ffffff">
                    <h2 style="color:#1a202c;margin-top:0">Administrator Invitation</h2>
                    <p>Hello,</p>
                    <p>You have been invited to join <strong>' . e($appName) . '</strong> as a <strong>' . e($this->roleName) . '</strong>.</p>
                    <p>Please click the button below to accept your invitation and set up your administrator account:</p>
                    <div style="text-align:center;margin:30px 0">
                        <a href="' . e($this->acceptUrl) . '" style="background-color:#2563eb;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block">
                            Accept Invitation
                        </a>
                    </div>
                    <p style="font-size:13px;color:#64748b;word-break:break-all">
                        Or copy and paste this link into your browser:<br>
                        <a href="' . e($this->acceptUrl) . '" style="color:#2563eb">' . e($this->acceptUrl) . '</a>
                    </p>
                    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">
                    <p style="color:#64748b;font-size:13px;margin-bottom:0">
                        This invitation link is valid until <strong>' . e($this->expiryDate) . '</strong>. If you were not expecting this invitation, you can safely ignore this email.
                    </p>
                </div>
            '
        );
    }
}
