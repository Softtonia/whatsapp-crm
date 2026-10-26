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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dateTime('dispatched_at')->nullable()->after('scheduled_at');
            $table->index(['send_type', 'is_active', 'scheduled_at', 'dispatched_at'], 'idx_campaigns_schedule_dispatch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('idx_campaigns_schedule_dispatch');
            $table->dropColumn('dispatched_at');
        });
    }
};
