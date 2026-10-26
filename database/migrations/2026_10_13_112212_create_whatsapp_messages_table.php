
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('sent_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Meta message ID; nullable for internal notes.
            $table->string('provider_message_id', 255)->nullable()->unique();

            $table->string('direction', 20);
            $table->string('type', 30);
            $table->longText('body')->nullable();

            $table->string('media_id', 255)->nullable();
            $table->string('media_mime_type', 150)->nullable();
            $table->string('media_sha256', 255)->nullable();
            $table->string('media_filename')->nullable();

            $table->string('status', 30)->default('pending');

            $table->json('context')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index(['direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
