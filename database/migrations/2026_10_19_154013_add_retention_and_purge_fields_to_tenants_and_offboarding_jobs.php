
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamp('legal_hold_at')->nullable();
            $table->text('legal_hold_reason')->nullable();
        });

        Schema::table('tenant_offboarding_jobs', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('retention_until')->nullable()->index();
            $table->timestamp('purge_eligible_at')->nullable()->index();

            $table->string('purge_status', 30)->default('not_requested');

            $table->foreignId('purge_requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('purge_approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('purge_requested_at')->nullable();
            $table->timestamp('purge_approved_at')->nullable();
            $table->timestamp('purge_started_at')->nullable();
            $table->timestamp('purge_completed_at')->nullable();
            $table->text('purge_reason')->nullable();
            $table->json('purge_preview')->nullable();
        });

        Schema::table('tenant_status_history', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);

            $table->unsignedBigInteger('tenant_id')
                ->nullable()
                ->change();

            $table->foreign(
                'tenant_id',
                'tenant_status_history_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::table('tenant_offboarding_jobs', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);

            $table->unsignedBigInteger('tenant_id')
                ->nullable()
                ->change();

            $table->foreign(
                'tenant_id',
                'tenant_offboarding_jobs_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::table('tenant_subscription_events', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);

            $table->unsignedBigInteger('tenant_id')
                ->nullable()
                ->change();

            $table->foreign(
                'tenant_id',
                'tenant_subscription_events_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->dropForeign(['subscription_id']);

            $table->unsignedBigInteger('subscription_id')
                ->nullable()
                ->change();

            $table->foreign(
                'subscription_id',
                'tenant_subscription_events_subscription_id_foreign'
            )
                ->references('id')
                ->on('tenant_subscriptions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_subscription_events', function (Blueprint $table) {
            $table->dropForeign(
                'tenant_subscription_events_subscription_id_foreign'
            );

            $table->foreign(
                'subscription_id',
                'tenant_subscription_events_subscription_id_foreign'
            )
                ->references('id')
                ->on('tenant_subscriptions')
                ->nullOnDelete();

            $table->dropForeign(
                'tenant_subscription_events_tenant_id_foreign'
            );

            $table->foreign(
                'tenant_id',
                'tenant_subscription_events_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::table('tenant_status_history', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);

            $table->foreign(
                'tenant_id',
                'tenant_status_history_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::table('tenant_offboarding_jobs', function (Blueprint $table) {
            $table->dropIndex(['retention_until']);
            $table->dropIndex(['purge_eligible_at']);

            $table->dropConstrainedForeignId('purge_requested_by');
            $table->dropConstrainedForeignId('purge_approved_by');

            $table->dropColumn([
                'archived_at',
                'retention_until',
                'purge_eligible_at',
                'purge_status',
                'purge_requested_at',
                'purge_approved_at',
                'purge_started_at',
                'purge_completed_at',
                'purge_reason',
                'purge_preview',
            ]);

            $table->dropForeign(['tenant_id']);

            $table->foreign(
                'tenant_id',
                'tenant_offboarding_jobs_tenant_id_foreign'
            )
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);

            $table->dropColumn([
                'archived_at',
                'legal_hold_at',
                'legal_hold_reason',
            ]);
        });

        // Nullable tenant references remain nullable to protect retained history.
    }
};
