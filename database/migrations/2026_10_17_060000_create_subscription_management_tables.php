<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('INR');
            $table->string('billing_cycle', 20)->default('monthly'); // monthly, yearly, custom
            $table->boolean('is_active')->default(true);
            $table->json('features')->nullable();

            // Configurable limits (null represents unlimited)
            $table->unsignedInteger('max_whatsapp_messages')->nullable();
            $table->unsignedInteger('max_ai_replies')->nullable();
            $table->unsignedInteger('max_team_members')->nullable();
            $table->unsignedInteger('max_whatsapp_accounts')->nullable();
            $table->unsignedInteger('max_contacts')->nullable();

        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')
                ->constrained('subscription_plans')
                ->restrictOnDelete();
            $table->string('status', 30)->default('active'); // active, expired, cancelled, suspended
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('whatsapp_messages_count')->default(0);
            $table->unsignedInteger('ai_replies_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period_start', 'period_end'], 'tenant_usage_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_records');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
