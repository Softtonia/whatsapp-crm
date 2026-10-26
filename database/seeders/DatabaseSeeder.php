<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(EmailTemplateSeeder::class);
        $this->call(CampaignSeeder::class);
        $this->call(SubscriptionPlanSeeder::class);
    }
}
