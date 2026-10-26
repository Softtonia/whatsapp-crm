<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend tenant_whats_app_accounts with sync and quality rating columns
        Schema::table('tenant_whats_app_accounts', function (Blueprint $table) {
            $table->string('business_name', 190)->nullable()->after('waba_id');
            $table->string('quality_rating', 50)->nullable()->after('display_phone_number');
            $table->timestamp('last_synced_at')->nullable()->after('status');
            $table->text('last_sync_error')->nullable()->after('last_synced_at');
        });

        // 2. Create whatsapp_webhook_events table for durable event logging, monitoring and retries
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->nullable()->index();
            $table->string('idempotency_key', 190)->unique();
            $table->string('event_type', 50)->index();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('phone_number_id', 100)->nullable()->index();
            $table->string('waba_id', 100)->nullable()->index();
            $table->json('payload');
            $table->string('processing_status', 30)->default('received')->index(); // received, processed, failed
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'processing_status']);
            $table->index(['created_at', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');

        Schema::table('tenant_whats_app_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'business_name',
                'quality_rating',
                'last_synced_at',
                'last_sync_error',
            ]);
        });
    }
};
