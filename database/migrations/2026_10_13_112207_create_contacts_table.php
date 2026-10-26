<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('phone', 30);
            $table->string('normalized_phone', 30);
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('source', 50)->default('whatsapp');

            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'normalized_phone'],
                'contacts_tenant_normalized_phone_unique'
            );

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
