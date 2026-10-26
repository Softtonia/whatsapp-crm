<?php

namespace Database\Seeders;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'description' => 'For small businesses starting with WhatsApp CRM.',
                'price' => 999,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'features' => [
                    'WhatsApp CRM',
                    'Contact Management',
                    'AI Replies',
                ],
                'max_whatsapp_messages' => 5000,
                'max_ai_replies' => 1000,
                'max_team_members' => 5,
                'max_whatsapp_accounts' => 1,
                'max_contacts' => 10000,

                'entitlement_features' => [
                    'white_label_enabled' => false,
                    'custom_domain_enabled' => false,
                ],
            ],
            [
                'name' => 'Professional',
                'description' => 'For growing businesses with larger teams.',
                'price' => 2999,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'features' => [
                    'WhatsApp CRM',
                    'Advanced AI Replies',
                    'Contact Management',
                    'Team Collaboration',
                    'Lead Management',
                ],
                'max_whatsapp_messages' => 25000,
                'max_ai_replies' => 5000,
                'max_team_members' => 15,
                'max_whatsapp_accounts' => 3,
                'max_contacts' => 50000,

                'entitlement_features' => [
                    'white_label_enabled' => false,
                    'custom_domain_enabled' => false,
                ],
            ],
            [
                'name' => 'Enterprise',
                'description' => 'For businesses with high-volume CRM needs.',
                'price' => 9999,
                'currency' => 'INR',
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'features' => [
                    'WhatsApp CRM',
                    'Advanced AI Automation',
                    'Contact Management',
                    'Lead Management',
                    'Priority Support',
                    'White-label Branding',
                    'Custom Domain',
                ],
                'max_whatsapp_messages' => 100000,
                'max_ai_replies' => 25000,
                'max_team_members' => 50,
                'max_whatsapp_accounts' => 10,
                'max_contacts' => 250000,

                'entitlement_features' => [
                    'white_label_enabled' => true,
                    'custom_domain_enabled' => true,
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $slug = Str::slug($planData['name']);

            $entitlementFeatures = $planData['entitlement_features'];

            // These are stored in the plan_features table, not the plans table.
            unset($planData['entitlement_features']);

            $planData['slug'] = $slug;

            // Preserve existing plan details on subsequent seed runs.
            $plan = SubscriptionPlan::firstOrCreate(
                ['slug' => $slug],
                $planData
            );

            foreach ($entitlementFeatures as $featureKey => $enabled) {
                $featureNames = [
                    'white_label_enabled' => 'White Label',
                    'custom_domain_enabled' => 'Custom Domain',
                ];

                // Create missing features or update their entitlement values.
                PlanFeature::updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'feature_key' => $featureKey,
                    ],
                    [
                        'feature_name' => $featureNames[$featureKey],
                        'feature_type' => 'boolean',
                        'value' => $enabled,
                    ]
                );
            }
        }
    }
}
