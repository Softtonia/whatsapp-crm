<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->unique()
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('brand_name', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('support_email')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_custom_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->string('hostname', 190)->unique();
            $table->string('verification_status', 30)->default('pending')->index();
            $table->string('verification_record_type', 10)->default('TXT');
            $table->string('verification_hostname', 190);
            $table->string('verification_token', 64);
            $table->text('expected_record_value');
            $table->timestamp('verified_at')->nullable()->index();
            $table->string('tls_status', 30)->default('pending')->index();
            $table->json('tls_metadata')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('last_verification_attempt_at')->nullable();
            $table->text('last_verification_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_custom_domains');
        Schema::dropIfExists('tenant_brandings');
    }
};
