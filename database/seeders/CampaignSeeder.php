<?php

namespace Database\Seeders;

use App\Enums\CampaignEvent;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = EmailTemplate::all()->keyBy('key');

        $campaigns = [
            [
                'name' => 'Forgot Password Trigger',
                'email_template_id' => $templates->get('FORGOT_PASSWORD')?->id ?? $templates->get('ADMIN_PASSWORD_RESET_OTP')?->id,
                'send_type' => 'trigger',
                'event' => CampaignEvent::FORGOT_PASSWORD->value,
                'is_active' => true,
            ],
            [
                'name' => 'Reset Password Confirmation Trigger',
                'email_template_id' => $templates->get('RESET_PASSWORD')?->id,
                'send_type' => 'trigger',
                'event' => CampaignEvent::RESET_PASSWORD->value,
                'is_active' => true,
            ],
            [
                'name' => 'OTP Verification Trigger',
                'email_template_id' => $templates->get('OTP_VERIFICATION')?->id,
                'send_type' => 'trigger',
                'event' => CampaignEvent::OTP_VERIFICATION->value,
                'is_active' => true,
            ],
        ];

        foreach ($campaigns as $campaign) {
            if (! empty($campaign['email_template_id'])) {
                Campaign::updateOrCreate(
                    [
                        'event' => $campaign['event'],
                        'send_type' => 'trigger',
                    ],
                    $campaign
                );
            }
        }
    }
}
