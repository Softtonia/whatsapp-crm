
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Link conversation with CRM contact.
            $table->foreignId('contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            $table->foreignId('whatsapp_account_id')
                ->constrained('tenant_whats_app_accounts')
                ->restrictOnDelete();

            $table->string('customer_phone', 30);
            $table->string('customer_name')->nullable();

            $table->string('status', 20)->default('open');

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_human_takeover')->default(false);

            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 500)->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'customer_phone'],
                'conversations_tenant_customer_unique'
            );

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
