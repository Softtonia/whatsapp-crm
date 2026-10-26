<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('actor_type')->nullable(); // e.g. 'super_admin'
                $table->string('event_type')->index();     // e.g. 'admin.login', 'password.changed'
                $table->string('category')->index();       // e.g. 'authentication', 'profile', 'security', 'account'
                $table->string('description', 500);
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['category', 'created_at']);
                $table->index(['event_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('admin_security_challenges')) {
            Schema::create('admin_security_challenges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('purpose', 32); // 'password_change', 'email_change'
                $table->string('target_email'); // current email for password_change, proposed email for email_change
                $table->string('otp_hash');
                $table->text('otp_encrypted')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();
                $table->string('auth_token_hash')->nullable();
                $table->timestamp('auth_token_expires_at')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('resend_count')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'purpose', 'expires_at']);
                $table->index(['user_id', 'purpose', 'consumed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_security_challenges');
        Schema::dropIfExists('activity_logs');
    }
};
