
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks bulk actions initiated by a super admin.
        Schema::create('tenant_bulk_operations', function (Blueprint $table) {
            $table->id();

            $table->string('operation_type', 60);
            $table->string('status', 30)->default('pending');

            $table->foreignId('initiated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->json('filters')->nullable();
            $table->json('options')->nullable();

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('successful_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);

            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['initiated_by', 'created_at']);
        });

        // Tracks the result of each item in a bulk operation.
        Schema::create('tenant_bulk_operation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bulk_operation_id')
                ->constrained('tenant_bulk_operations')
                ->restrictOnDelete();

            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->nullOnDelete();

            $table->string('item_type', 60);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('status', 30)->default('pending');

            $table->string('idempotency_key', 100)->nullable();
            $table->text('message')->nullable();
            $table->json('result')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['bulk_operation_id', 'idempotency_key'],
                'tenant_bulk_items_idempotency_unique'
            );

            $table->index(['bulk_operation_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Tracks tenant data export requests and private file locations.
        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->nullOnDelete();

            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('export_type', 60);
            $table->string('format', 20)->default('csv');
            $table->string('status', 30)->default('pending');

            $table->json('filters')->nullable();

            $table->string('file_disk', 50)->nullable();
            $table->string('file_path', 1024)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedBigInteger('record_count')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });

        // Tracks tenant offboarding requests and their lifecycle.
        Schema::create('tenant_offboarding_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();

            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 30)->default('pending');
            $table->text('reason');
            $table->json('options')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'scheduled_for']);
        });

        // Tracks individual offboarding steps for retries and recovery.
        Schema::create('tenant_offboarding_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offboarding_job_id')
                ->constrained('tenant_offboarding_jobs')
                ->restrictOnDelete();

            $table->string('step_key', 100);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);

            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['offboarding_job_id', 'step_key'],
                'tenant_offboarding_job_step_unique'
            );

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_offboarding_steps');
        Schema::dropIfExists('tenant_offboarding_jobs');
        Schema::dropIfExists('tenant_exports');
        Schema::dropIfExists('tenant_bulk_operation_items');
        Schema::dropIfExists('tenant_bulk_operations');
    }
};
