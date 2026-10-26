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
        Schema::create('contact_interactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contact_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('whatsapp_message_id')
                ->nullable()
                ->constrained('whatsapp_messages')
                ->nullOnDelete();

            $table->string('type', 50);
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->timestamps();

            $table->unique(
                'whatsapp_message_id',
                'contact_interactions_message_unique'
            );

            $table->index(['contact_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_interactions');
    }
};
