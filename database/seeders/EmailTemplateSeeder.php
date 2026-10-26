<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'key' => 'ADMIN_PASSWORD_RESET_OTP',
                'name' => 'Admin Password Reset OTP',
                'subject' => 'Admin Password Reset OTP - {{CompanyName}}',
                'type' => 'html',
                'is_active' => true,
                'body' => <<<'HTML'
<div style="font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
    <h2 style="color: #1e293b; margin-top: 0;">Admin Password Reset</h2>
    <p>Hello {{AdminName}},</p>
    <p>Use this one-time password to reset your admin password:</p>
    <div style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center; background: #f8fafc; padding: 16px; border: 1px dashed #cbd5e1; border-radius: 6px; margin: 20px 0; color: #0f172a;">
        {{Otp}}
    </div>
    <p>This OTP expires in <strong>{{ExpiryMinutes}} minutes</strong>.</p>
    <p style="color: #64748b; font-size: 14px;">If you did not request this password reset, please ignore this email or contact support at <a href="mailto:{{SupportEmail}}">{{SupportEmail}}</a>.</p>
    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;" />
    <p style="color: #94a3b8; font-size: 12px; margin-bottom: 0;">&copy; {{CompanyName}} Security Team</p>
</div>
HTML
            ],
            [
                'key' => 'FORGOT_PASSWORD',
                'name' => 'Forgot Password Notification',
                'subject' => 'Password Reset Request - {{CompanyName}}',
                'type' => 'html',
                'is_active' => true,
                'body' => <<<'HTML'
<div style="font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
    <h2 style="color: #1e293b; margin-top: 0;">Password Reset Request</h2>
    <p>Hello {{UserName}},</p>
    <p>We received a request to reset your password for your {{CompanyName}} account.</p>
    <div style="font-size: 28px; font-weight: bold; letter-spacing: 6px; text-align: center; background: #f8fafc; padding: 14px; border: 1px dashed #cbd5e1; border-radius: 6px; margin: 20px 0; color: #0f172a;">
        {{Otp}}
    </div>
    <p>This code will expire in <strong>{{ExpiryMinutes}} minutes</strong>.</p>
    <p style="color: #64748b; font-size: 14px;">If you did not request a password reset, please contact us at <a href="mailto:{{SupportEmail}}">{{SupportEmail}}</a>.</p>
</div>
HTML
            ],
            [
                'key' => 'RESET_PASSWORD',
                'name' => 'Password Reset Successful',
                'subject' => 'Your Password Has Been Reset - {{CompanyName}}',
                'type' => 'html',
                'is_active' => true,
                'body' => <<<'HTML'
<div style="font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
    <h2 style="color: #1e293b; margin-top: 0;">Password Reset Confirmation</h2>
    <p>Hello {{UserName}},</p>
    <p>Your password for <strong>{{CompanyName}}</strong> has been successfully reset.</p>
    <p>You may now log in using your new password.</p>
    <p style="color: #dc2626; font-size: 14px;">If you did not perform this action, please contact support immediately at <a href="mailto:{{SupportEmail}}">{{SupportEmail}}</a>.</p>
</div>
HTML
            ],
            [
                'key' => 'OTP_VERIFICATION',
                'name' => 'OTP Verification',
                'subject' => 'Your Verification Code - {{CompanyName}}',
                'type' => 'html',
                'is_active' => true,
                'body' => <<<'HTML'
<div style="font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
    <h2 style="color: #1e293b; margin-top: 0;">Verification Code</h2>
    <p>Hello {{UserName}},</p>
    <p>Your verification code for <strong>{{Purpose}}</strong> is:</p>
    <div style="font-size: 32px; font-weight: bold; letter-spacing: 6px; text-align: center; background: #f8fafc; padding: 14px; border: 1px dashed #cbd5e1; border-radius: 6px; margin: 20px 0; color: #0f172a;">
        {{Otp}}
    </div>
    <p>This verification code is valid for <strong>{{ExpiryMinutes}} minutes</strong>.</p>
    <p style="color: #64748b; font-size: 14px;">Never share your code with anyone. For assistance, contact <a href="mailto:{{SupportEmail}}">{{SupportEmail}}</a>.</p>
</div>
HTML
            ],
        ];

        foreach ($templates as $data) {
            EmailTemplate::updateOrCreate(
                ['key' => $data['key']],
                $data
            );
        }
    }
}
