<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('activity_logs', 'request_id')) {
                    $table->string('request_id', 64)->nullable()->index()->after('user_agent');
                }
                if (! Schema::hasColumn('activity_logs', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('request_id');
                }
                if (! Schema::hasColumn('activity_logs', 'status')) {
                    $table->string('status', 20)->default('success')->index()->after('tenant_id');
                }
                if (! Schema::hasColumn('activity_logs', 'old_values')) {
                    $table->json('old_values')->nullable()->after('metadata');
                }
                if (! Schema::hasColumn('activity_logs', 'new_values')) {
                    $table->json('new_values')->nullable()->after('old_values');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $columns = ['request_id', 'tenant_id', 'status', 'old_values', 'new_values'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('activity_logs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
