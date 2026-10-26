<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_password_reset_otps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('email');
            $table->string('otp_hash');
            $table->text('otp_encrypted')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            $table->string('reset_token_hash')->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index(['email', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_otps');
    }
};