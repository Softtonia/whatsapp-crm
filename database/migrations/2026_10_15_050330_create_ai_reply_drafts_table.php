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
        Schema::create('ai_reply_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('inbound_message_id')
                ->constrained('whatsapp_messages')
                ->cascadeOnDelete();

            $table->foreignId('knowledge_id')
                ->nullable()
                ->constrained('tenant_knowledge')
                ->nullOnDelete();

            $table->text('suggested_reply');
            $table->text('edited_reply')->nullable();

            $table->string('intent', 50)->default('unknown');
            $table->boolean('handoff_required')->default(false);
            $table->string('handoff_reason')->nullable();

            $table->string('status', 20)->default('pending');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['conversation_id', 'created_at']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_reply_drafts');
    }
};
