<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_usage_records')) {
            if (! Schema::hasColumn('tenant_usage_records', 'campaigns_count')) {
                Schema::table('tenant_usage_records', function (Blueprint $table) {
                    $table->unsignedInteger('campaigns_count')->default(0)->after('ai_replies_count');
                });
            }
        }

        if (! Schema::hasTable('tenant_usage_ledgers')) {
            Schema::create('tenant_usage_ledgers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('metric_key', 64);
                $table->integer('quantity')->default(1);
                $table->string('source_type', 100)->nullable();
                $table->string('source_id', 100)->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'metric_key', 'occurred_at'], 'usage_ledger_tenant_metric_idx');
                $table->index(['tenant_id', 'period_start', 'period_end'], 'usage_ledger_tenant_period_idx');
                $table->unique(['tenant_id', 'metric_key', 'idempotency_key'], 'usage_ledger_tenant_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_ledgers');

        if (Schema::hasTable('tenant_usage_records') && Schema::hasColumn('tenant_usage_records', 'campaigns_count')) {
            Schema::table('tenant_usage_records', function (Blueprint $table) {
                $table->dropColumn('campaigns_count');
            });
        }
    }
};
