<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscription_plans')) {
            throw new \RuntimeException(
                'Required table subscription_plans does not exist.'
            );
        }

        if (!Schema::hasTable('tenant_subscriptions')) {
            throw new \RuntimeException(
                'Required table tenant_subscriptions does not exist.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Extend existing subscription plans
        |--------------------------------------------------------------------------
        */

        $planColumns = [
            'code' => fn(Blueprint $t) =>
            $t->string('code', 100)->nullable()->unique(),

            'plan_type' => fn(Blueprint $t) =>
            $t->string('plan_type', 30)->default('standard'),

            'status' => fn(Blueprint $t) =>
            $t->string('status', 30)->default('published'),

            'visibility' => fn(Blueprint $t) =>
            $t->string('visibility', 30)->default('public'),

            'available_for_signup' => fn(Blueprint $t) =>
            $t->boolean('available_for_signup')->default(true),

            'trial_days' => fn(Blueprint $t) =>
            $t->unsignedSmallInteger('trial_days')->default(0),

            'trial_eligibility' => fn(Blueprint $t) =>
            $t->json('trial_eligibility')->nullable(),

            'setup_fee' => fn(Blueprint $t) =>
            $t->decimal('setup_fee', 12, 2)->default(0),

            'setup_fee_required' => fn(Blueprint $t) =>
            $t->boolean('setup_fee_required')->default(false),

            'onboarding_config' => fn(Blueprint $t) =>
            $t->json('onboarding_config')->nullable(),

            'sort_order' => fn(Blueprint $t) =>
            $t->unsignedInteger('sort_order')->default(0),

            'published_at' => fn(Blueprint $t) =>
            $t->timestamp('published_at')->nullable(),

            'archived_at' => fn(Blueprint $t) =>
            $t->timestamp('archived_at')->nullable(),

            'created_by' => fn(Blueprint $t) =>
            $t->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete(),

            'updated_by' => fn(Blueprint $t) =>
            $t->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete(),
            'created_at' => fn(Blueprint $t) =>
            $t->timestamp('created_at')->nullable(),

            'updated_at' => fn(Blueprint $t) =>
            $t->timestamp('updated_at')->nullable(),
            
            'deleted_at' => fn(Blueprint $t) =>
            $t->softDeletes(),
        ];

        foreach ($planColumns as $column => $definition) {
            $this->addColumnIfMissing(
                'subscription_plans',
                $column,
                $definition
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Extend existing tenant subscriptions
        |--------------------------------------------------------------------------
        */

        $subscriptionColumns = [
            'billing_interval' => fn(Blueprint $t) =>
            $t->string('billing_interval', 20)->default('monthly'),

            'currency' => fn(Blueprint $t) =>
            $t->char('currency', 3)->default('INR'),

            'base_price' => fn(Blueprint $t) =>
            $t->decimal('base_price', 12, 2)->default(0),

            'setup_fee' => fn(Blueprint $t) =>
            $t->decimal('setup_fee', 12, 2)->default(0),

            'discount_amount' => fn(Blueprint $t) =>
            $t->decimal('discount_amount', 12, 2)->default(0),

            'trial_starts_at' => fn(Blueprint $t) =>
            $t->timestamp('trial_starts_at')->nullable(),

            'billing_period_starts_at' => fn(Blueprint $t) =>
            $t->timestamp('billing_period_starts_at')->nullable(),

            'billing_period_ends_at' => fn(Blueprint $t) =>
            $t->timestamp('billing_period_ends_at')->nullable(),

            'next_billing_at' => fn(Blueprint $t) =>
            $t->timestamp('next_billing_at')->nullable(),

            'grace_period_ends_at' => fn(Blueprint $t) =>
            $t->timestamp('grace_period_ends_at')->nullable(),

            'cancel_at_period_end' => fn(Blueprint $t) =>
            $t->boolean('cancel_at_period_end')->default(false),

            'ended_at' => fn(Blueprint $t) =>
            $t->timestamp('ended_at')->nullable(),

            'assigned_by' => fn(Blueprint $t) =>
            $t->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete(),

            'metadata' => fn(Blueprint $t) =>
            $t->json('metadata')->nullable(),
        ];

        foreach ($subscriptionColumns as $column => $definition) {
            $this->addColumnIfMissing(
                'tenant_subscriptions',
                $column,
                $definition
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Plan versions
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_plan_versions')) {
            Schema::create(
                'subscription_plan_versions',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('plan_id')
                        ->constrained('subscription_plans')
                        ->restrictOnDelete();

                    $table->unsignedInteger('version_number');
                    $table->json('configuration');
                    $table->text('change_summary')->nullable();

                    $table->foreignId('published_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->timestamp('published_at')->nullable();
                    $table->timestamps();

                    $table->unique(['plan_id', 'version_number']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Plan prices
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_plan_prices')) {
            Schema::create(
                'subscription_plan_prices',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('plan_id')
                        ->constrained('subscription_plans')
                        ->restrictOnDelete();

                    $table->foreignId('version_id')
                        ->nullable()
                        ->constrained('subscription_plan_versions')
                        ->nullOnDelete();

                    $table->string('billing_interval', 20);
                    $table->unsignedSmallInteger('interval_count')->default(1);
                    $table->char('currency', 3)->default('INR');
                    $table->decimal('amount', 12, 2);
                    $table->decimal('setup_fee', 12, 2)->default(0);
                    $table->boolean('is_active')->default(true);
                    $table->timestamp('effective_from')->nullable();
                    $table->timestamp('effective_until')->nullable();
                    $table->timestamps();

                    $table->index([
                        'plan_id',
                        'billing_interval',
                        'currency',
                    ]);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Link subscriptions to plan versions and prices
        |--------------------------------------------------------------------------
        */

        $this->addColumnIfMissing(
            'tenant_subscriptions',
            'plan_version_id',
            fn(Blueprint $t) => $t->foreignId('plan_version_id')
                ->nullable()
                ->constrained('subscription_plan_versions')
                ->nullOnDelete()
        );

        $this->addColumnIfMissing(
            'tenant_subscriptions',
            'plan_price_id',
            fn(Blueprint $t) => $t->foreignId('plan_price_id')
                ->nullable()
                ->constrained('subscription_plan_prices')
                ->nullOnDelete()
        );

        /*
        |--------------------------------------------------------------------------
        | 6. Plan features
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('plan_features')) {
            Schema::create('plan_features', function (Blueprint $table) {
                $table->id();

                $table->foreignId('plan_id')
                    ->constrained('subscription_plans')
                    ->cascadeOnDelete();

                $table->foreignId('version_id')
                    ->nullable()
                    ->constrained('subscription_plan_versions')
                    ->nullOnDelete();

                $table->string('feature_key', 100);
                $table->string('feature_name', 150);
                $table->string('feature_type', 30);
                $table->json('value');
                $table->timestamps();

                $table->index(['plan_id', 'feature_key']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Plan onboarding settings
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('plan_onboarding_settings')) {
            Schema::create(
                'plan_onboarding_settings',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('plan_id')
                        ->constrained('subscription_plans')
                        ->cascadeOnDelete();

                    $table->foreignId('version_id')
                        ->nullable()
                        ->constrained('subscription_plan_versions')
                        ->nullOnDelete();

                    $table->string('step_key', 100);
                    $table->string('step_name', 150);
                    $table->boolean('is_required')->default(true);
                    $table->unsignedInteger('sort_order')->default(0);
                    $table->json('configuration')->nullable();
                    $table->timestamps();

                    $table->index(['plan_id', 'sort_order']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Plan change policies
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('plan_change_policies')) {
            Schema::create(
                'plan_change_policies',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('plan_id')
                        ->constrained('subscription_plans')
                        ->cascadeOnDelete();

                    $table->foreignId('version_id')
                        ->nullable()
                        ->constrained('subscription_plan_versions')
                        ->nullOnDelete();

                    $table->string('change_type', 30);
                    $table->string('effective_timing', 30)->default('immediate');
                    $table->string('proration_mode', 30)->default('none');
                    $table->boolean('approval_required')->default(false);
                    $table->unsignedSmallInteger('minimum_commitment_days')
                        ->nullable();
                    $table->boolean('allow_during_past_due')->default(false);
                    $table->json('policy_config')->nullable();
                    $table->timestamps();

                    $table->index(['plan_id', 'change_type']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Coupons
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->id();
                $table->string('code', 100)->unique();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('discount_type', 30);
                $table->decimal('discount_value', 12, 2);
                $table->char('currency', 3)->nullable();
                $table->decimal('max_discount_amount', 12, 2)->nullable();
                $table->string('duration_type', 30)->default('once');
                $table->unsignedSmallInteger('duration_months')->nullable();
                $table->unsignedInteger('max_redemptions')->nullable();
                $table->unsignedSmallInteger('max_redemptions_per_tenant')
                    ->nullable();
                $table->decimal('minimum_amount', 12, 2)->nullable();
                $table->json('eligible_plan_ids')->nullable();
                $table->boolean('first_subscription_only')->default(false);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->string('status', 30)->default('draft');

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index(['status', 'valid_from', 'valid_until']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Coupon redemptions
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $table) {
                $table->id();

                $table->foreignId('coupon_id')
                    ->constrained('coupons')
                    ->restrictOnDelete();

                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->restrictOnDelete();

                $table->foreignId('subscription_id')
                    ->nullable()
                    ->constrained('tenant_subscriptions')
                    ->nullOnDelete();

                $table->decimal('discount_amount', 12, 2);
                $table->char('currency', 3)->default('INR');
                $table->timestamp('redeemed_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'coupon_id']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 11. Subscription items
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_items')) {
            Schema::create('subscription_items', function (Blueprint $table) {
                $table->id();

                $table->foreignId('subscription_id')
                    ->constrained('tenant_subscriptions')
                    ->restrictOnDelete();

                $table->foreignId('price_id')
                    ->nullable()
                    ->constrained('subscription_plan_prices')
                    ->nullOnDelete();

                $table->string('item_type', 30)->default('recurring');
                $table->string('item_key', 100)->nullable();
                $table->string('description', 255);
                $table->decimal('quantity', 12, 4)->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->char('currency', 3)->default('INR');
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['subscription_id', 'is_active']);
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 12. Subscription change requests
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_change_requests')) {
            Schema::create(
                'subscription_change_requests',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_id')
                        ->constrained('tenant_subscriptions')
                        ->restrictOnDelete();

                    $table->foreignId('requested_plan_id')
                        ->nullable()
                        ->constrained('subscription_plans')
                        ->nullOnDelete();

                    $table->string('change_type', 30);
                    $table->string('effective_timing', 30)->default('immediate');
                    $table->string('status', 30)->default('pending');
                    $table->text('reason')->nullable();

                    $table->foreignId('requested_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->foreignId('reviewed_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->timestamp('reviewed_at')->nullable();
                    $table->timestamp('effective_at')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();

                    $table->index(['tenant_id', 'status']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 13. Subscription overrides
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_overrides')) {
            Schema::create(
                'subscription_overrides',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_id')
                        ->constrained('tenant_subscriptions')
                        ->restrictOnDelete();

                    $table->string('feature_key', 100);
                    $table->json('override_value');
                    $table->text('reason');
                    $table->timestamp('starts_at')->nullable();
                    $table->timestamp('ends_at')->nullable();
                    $table->boolean('is_active')->default(true);

                    $table->foreignId('created_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->foreignId('approved_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->timestamp('approved_at')->nullable();
                    $table->timestamps();

                    $table->index([
                        'tenant_id',
                        'feature_key',
                        'is_active',
                    ]);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 14. Subscription invoices
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_invoices')) {
            Schema::create(
                'subscription_invoices',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_id')
                        ->constrained('tenant_subscriptions')
                        ->restrictOnDelete();

                    $table->string('invoice_number', 100)->unique();
                    $table->string('status', 30)->default('draft');
                    $table->char('currency', 3)->default('INR');
                    $table->decimal('subtotal', 12, 2)->default(0);
                    $table->decimal('discount_total', 12, 2)->default(0);
                    $table->decimal('tax_total', 12, 2)->default(0);
                    $table->decimal('total', 12, 2)->default(0);
                    $table->decimal('amount_paid', 12, 2)->default(0);
                    $table->decimal('amount_due', 12, 2)->default(0);
                    $table->timestamp('billing_period_start')->nullable();
                    $table->timestamp('billing_period_end')->nullable();
                    $table->timestamp('issued_at')->nullable();
                    $table->timestamp('due_at')->nullable();
                    $table->timestamp('paid_at')->nullable();
                    $table->timestamp('voided_at')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();

                    $table->index(['tenant_id', 'status']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 15. Invoice items
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_invoice_items')) {
            Schema::create(
                'subscription_invoice_items',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('invoice_id')
                        ->constrained('subscription_invoices')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_item_id')
                        ->nullable()
                        ->constrained('subscription_items')
                        ->nullOnDelete();

                    $table->string('item_type', 30);
                    $table->string('description', 255);
                    $table->decimal('quantity', 12, 4)->default(1);
                    $table->decimal('unit_price', 12, 2)->default(0);
                    $table->decimal('subtotal', 12, 2)->default(0);
                    $table->decimal('discount_amount', 12, 2)->default(0);
                    $table->decimal('tax_amount', 12, 2)->default(0);
                    $table->decimal('total', 12, 2)->default(0);
                    $table->timestamp('period_start')->nullable();
                    $table->timestamp('period_end')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();

                    $table->index('invoice_id');
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 16. Payments
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_payments')) {
            Schema::create(
                'subscription_payments',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('invoice_id')
                        ->constrained('subscription_invoices')
                        ->restrictOnDelete();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->string('provider', 50);
                    $table->string('provider_payment_id', 255)->nullable();
                    $table->string('provider_event_id', 255)->nullable()->unique();
                    $table->string('transaction_type', 30)->default('charge');
                    $table->string('status', 30)->default('pending');
                    $table->decimal('amount', 12, 2);
                    $table->char('currency', 3)->default('INR');
                    $table->string('failure_code', 100)->nullable();
                    $table->text('failure_message')->nullable();
                    $table->timestamp('paid_at')->nullable();
                    $table->timestamp('reconciled_at')->nullable();
                    $table->json('metadata')->nullable();
                    $table->timestamps();

                    $table->index(['invoice_id', 'status']);
                    $table->index(['tenant_id', 'created_at']);
                    $table->index(['provider', 'provider_payment_id']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 17. Subscription access history
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('subscription_access_events')) {
            Schema::create(
                'subscription_access_events',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_id')
                        ->constrained('tenant_subscriptions')
                        ->restrictOnDelete();

                    $table->string('event_type', 40);
                    $table->string('previous_access_status', 30)->nullable();
                    $table->string('new_access_status', 30);
                    $table->text('reason')->nullable();
                    $table->timestamp('effective_at');

                    $table->foreignId('performed_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->json('metadata')->nullable();
                    $table->timestamp('created_at')->useCurrent();

                    $table->index(['tenant_id', 'effective_at']);
                    $table->index(['subscription_id', 'event_type']);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 18. Subscription audit events
        |--------------------------------------------------------------------------
        */

        if (!Schema::hasTable('tenant_subscription_events')) {
            Schema::create(
                'tenant_subscription_events',
                function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('tenant_id')
                        ->constrained('tenants')
                        ->restrictOnDelete();

                    $table->foreignId('subscription_id')
                        ->nullable()
                        ->constrained('tenant_subscriptions')
                        ->nullOnDelete();

                    $table->string('event_type', 60);
                    $table->json('old_values')->nullable();
                    $table->json('new_values')->nullable();
                    $table->text('reason')->nullable();

                    $table->foreignId('performed_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();

                    $table->string('source', 30)->default('system');
                    $table->char('correlation_id', 36)->nullable();
                    $table->timestamp('created_at')->useCurrent();

                    $table->index(['tenant_id', 'created_at']);
                    $table->index(['subscription_id', 'event_type']);
                }
            );
        } else {
            $this->addColumnIfMissing(
                'tenant_subscription_events',
                'source',
                fn(Blueprint $t) =>
                $t->string('source', 30)->default('system')
            );

            $this->addColumnIfMissing(
                'tenant_subscription_events',
                'correlation_id',
                fn(Blueprint $t) =>
                $t->char('correlation_id', 36)->nullable()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 19. Extend existing usage records
        |--------------------------------------------------------------------------
        */

        if (Schema::hasTable('tenant_usage_records')) {
            $this->addColumnIfMissing(
                'tenant_usage_records',
                'subscription_id',
                fn(Blueprint $t) => $t->foreignId('subscription_id')
                    ->nullable()
                    ->constrained('tenant_subscriptions')
                    ->nullOnDelete()
            );

            $this->addColumnIfMissing(
                'tenant_usage_records',
                'metric_key',
                fn(Blueprint $t) => $t->string('metric_key', 100)
                    ->nullable()
                    ->index()
            );

            $this->addColumnIfMissing(
                'tenant_usage_records',
                'quantity',
                fn(Blueprint $t) => $t->decimal('quantity', 20, 4)
                    ->nullable()
            );
        }
    }

    /**
     * Add a column only if its table exists and the column is missing.
     *
     * The existence checks must happen outside Schema::table().
     */
    private function addColumnIfMissing(
        string $tableName,
        string $columnName,
        \Closure $definition
    ): void {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition) {
            $definition($table);
        });
    }

    /**
     * Preserve subscription, invoice, payment and audit history.
     */
    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
