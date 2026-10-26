<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('status', 20)->default('active')->after('remember_token')->index();
            });
        }

        if (! Schema::hasTable('admin_invitations')) {
            Schema::create('admin_invitations', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('role_name', 100);
                $table->string('token_hash', 64)->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at')->index();
                $table->timestamp('accepted_at')->nullable()->index();
                $table->timestamps();

                $table->index(['email', 'accepted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');

        if (Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
