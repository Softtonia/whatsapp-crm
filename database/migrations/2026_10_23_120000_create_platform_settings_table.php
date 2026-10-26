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
        if (! Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group', 50)->index(); // 'general', 'integrations', 'security'
                $table->string('key', 100)->unique();
                $table->text('value')->nullable(); // Plain value or encrypted string
                $table->string('type', 30)->default('string'); // 'string', 'boolean', 'integer', 'json', 'encrypted'
                $table->boolean('is_sensitive')->default(false);
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe rollback: Only drop if the table exists
        if (Schema::hasTable('platform_settings')) {
            Schema::dropIfExists('platform_settings');
        }
    }
};
