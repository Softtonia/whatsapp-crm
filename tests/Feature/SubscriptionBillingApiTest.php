<?php

namespace Tests\Feature;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceItem;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionBillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;
    protected Tenant $tenant;
    protected Tenant $otherTenant;
    protected TenantSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Super Admin', 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->regularUser = User::factory()->create();

        $this->tenant = Tenant::create([
            'company_name' => 'Billing Test Tenant',
            'slug' => 'billing-test-tenant',
            'status' => 'active',
        ]);

        $this->otherTenant = Tenant::create([
            'company_name' => 'Other Billing Tenant',
            'slug' => 'other-billing-tenant',
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Billing Test Plan',
            'slug' => 'billing-test-plan',
            'price' => 999,
            'currency' => 'INR',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'status' => 'published',
            'available_for_signup' => true,
        ]);

        $this->subscription = TenantSubscription::create([
            'tenant_id' => $this->tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'billing_interval' => 'monthly',
            'currency' => 'INR',
            'base_price' => 999,
            'cancel_at_period_end' => false,
        ]);
    }

    private function createInvoice(
        ?Tenant $tenant = null,
        string $status = 'open',
        array $overrides = []
    ): SubscriptionInvoice {
        $tenant ??= $this->tenant;

        $subscription = $tenant->id === $this->tenant->id
            ? $this->subscription
            : TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'subscription_plan_id' => $this->subscription->subscription_plan_id,
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
                'cancel_at_period_end' => false,
            ]);

        return SubscriptionInvoice::create(array_merge([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-' . uniqid(),
            'status' => $status,
            'currency' => 'INR',
            'subtotal' => 999,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 999,
            'amount_paid' => 0,
            'amount_due' => 999,
            'issued_at' => now(),
        ], $overrides));
    }

    private function createPayment(
        SubscriptionInvoice $invoice,
        array $overrides = []
    ): SubscriptionPayment {
        return SubscriptionPayment::create(array_merge([
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
            'provider' => 'test',
            'transaction_type' => 'charge',
            'status' => 'pending',
            'amount' => 999,
            'currency' => 'INR',
        ], $overrides));
    }

    public function test_guest_cannot_list_invoices(): void
    {
        $this->getJson('/api/auth/admin/invoices')
            ->assertUnauthorized();
    }

    public function test_regular_user_cannot_list_invoices(): void
    {
        $this->actingAs($this->regularUser, 'sanctum')
            ->getJson('/api/auth/admin/invoices')
            ->assertForbidden();
    }

    public function test_super_admin_can_list_invoices(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/auth/admin/invoices')
            ->assertOk()
            ->assertJsonFragment([
                'invoice_number' => $invoice->invoice_number,
            ]);
    }

    public function test_invoice_list_can_be_filtered_by_tenant_and_status(): void
    {
        $matching = $this->createInvoice($this->tenant, 'paid');
        $this->createInvoice($this->tenant, 'open');
        $this->createInvoice($this->otherTenant, 'paid');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson(
                '/api/auth/admin/invoices?tenant_id=' .
                    $this->tenant->id . '&status=paid'
            )
            ->assertOk()
            ->assertJsonFragment([
                'invoice_number' => $matching->invoice_number,
            ])
            ->assertJsonMissing([
                'status' => 'open',
            ]);
    }

    public function test_invoice_details_include_items_and_payments(): void
    {
        $invoice = $this->createInvoice();

        SubscriptionInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => 'subscription',
            'description' => 'Monthly subscription',
            'quantity' => 1,
            'unit_price' => 999,
            'subtotal' => 999,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 999,
        ]);

        $payment = $this->createPayment($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/auth/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.items.0.description', 'Monthly subscription')
            ->assertJsonPath('data.payments.0.id', $payment->id);
    }

    public function test_admin_can_filter_payments_by_status(): void
    {
        $invoice = $this->createInvoice();

        $failed = $this->createPayment($invoice, [
            'status' => 'failed',
            'failure_code' => 'PAYMENT_DECLINED',
            'failure_message' => 'Payment was declined.',
        ]);

        $this->createPayment($invoice, [
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/auth/admin/payments?status=failed')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $failed->id,
                'status' => 'failed',
                'failure_code' => 'PAYMENT_DECLINED',
            ]);
    }

    public function test_payment_details_include_invoice_and_failure_information(): void
    {
        $invoice = $this->createInvoice();

        $payment = $this->createPayment($invoice, [
            'status' => 'failed',
            'failure_code' => 'DECLINED',
            'failure_message' => 'Issuer declined payment.',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/auth/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.failure_code', 'DECLINED')
            ->assertJsonPath(
                'data.invoice.invoice_number',
                $invoice->invoice_number
            );
    }

    public function test_invalid_pagination_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/auth/admin/invoices?per_page=101')
            ->assertUnprocessable();
    }

    public function test_missing_invoice_returns_not_found(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/auth/admin/invoices/999999')
            ->assertNotFound();
    }

    public function test_invoice_payment_endpoint_returns_related_payments(): void
    {
        $invoice = $this->createInvoice();
        $payment = $this->createPayment($invoice);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/auth/admin/invoices/{$invoice->id}/payments")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $payment->id,
            ]);
    }
    public function test_guest_cannot_download_invoice_pdf(): void
    {
        $invoice = $this->createInvoice($this->tenant);

        $this->getJson(
            "/api/auth/admin/invoices/{$invoice->id}/download"
        )->assertUnauthorized();
    }

    public function test_regular_user_cannot_download_invoice_pdf(): void
    {
        $invoice = $this->createInvoice($this->tenant);

        $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/auth/admin/invoices/{$invoice->id}/download")
            ->assertForbidden();
    }

    public function test_super_admin_can_download_invoice_pdf(): void
    {
        $invoice = $this->createInvoice($this->tenant);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/auth/admin/invoices/{$invoice->id}/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_razorpay_webhook_rejects_invalid_signature(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

        $body = json_encode(['event' => 'payment.captured']);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => str_repeat('a', 64),
                'HTTP_X_RAZORPAY_EVENT_ID' => 'evt-invalid-signature',
            ],
            $body
        );

        $response->assertUnauthorized();
    }

    public function test_razorpay_webhook_rejects_amount_mismatch(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

        $invoice = $this->createInvoice($this->tenant);

        $invoice->forceFill([
            'currency' => 'INR',
            'total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'status' => 'issued',
        ])->save();

        $body = json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_amount_mismatch',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'captured',
                        'captured' => true,
                        'notes' => ['invoice_id' => $invoice->id],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->sendSignedRazorpayWebhook(
            $body,
            'evt-amount-mismatch'
        );

        $response->assertStatus(422);

        $this->assertSame(
            '100.00',
            number_format((float) $invoice->fresh()->amount_due, 2, '.', '')
        );

        $this->assertDatabaseMissing('subscription_payments', [
            'provider_event_id' => 'evt-amount-mismatch',
        ]);
    }

    public function test_razorpay_duplicate_event_does_not_apply_payment_twice(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

        $invoice = $this->createInvoice($this->tenant);

        $invoice->forceFill([
            'currency' => 'INR',
            'total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'status' => 'issued',
        ])->save();

        $body = json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_duplicate_test',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'status' => 'captured',
                        'captured' => true,
                        'notes' => ['invoice_id' => $invoice->id],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $first = $this->sendSignedRazorpayWebhook($body, 'evt-duplicate-test');
        $first->assertOk();

        $second = $this->sendSignedRazorpayWebhook($body, 'evt-duplicate-test');
        $second->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(
            '100.00',
            number_format((float) $invoice->fresh()->amount_paid, 2, '.', '')
        );

        $this->assertDatabaseCount('subscription_payments', 1);
    }

    private function sendSignedRazorpayWebhook(
        string $body,
        string $eventId
    ): \Illuminate\Testing\TestResponse {
        $secret = 'test-webhook-secret';

        return $this->call(
            'POST',
            '/api/v1/webhooks/payments/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac(
                    'sha256',
                    $body,
                    $secret
                ),
                'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
            ],
            $body
        );
    }

    public function test_razorpay_webhook_rejects_currency_mismatch(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

        $invoice = $this->createInvoice($this->tenant);

        $invoice->forceFill([
            'currency' => 'INR',
            'total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'status' => 'open',
        ])->save();

        $body = json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_currency_mismatch',
                        'amount' => 10000,
                        'currency' => 'USD',
                        'status' => 'captured',
                        'captured' => true,
                        'notes' => ['invoice_id' => $invoice->id],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->sendSignedRazorpayWebhook(
            $body,
            'evt-currency-mismatch'
        );

        $response->assertStatus(422);

        $invoice->refresh();

        $this->assertSame('INR', $invoice->currency);
        $this->assertSame(
            '0.00',
            number_format((float) $invoice->amount_paid, 2, '.', '')
        );
        $this->assertSame(
            '100.00',
            number_format((float) $invoice->amount_due, 2, '.', '')
        );

        $this->assertDatabaseMissing('subscription_payments', [
            'provider_event_id' => 'evt-currency-mismatch',
        ]);
    }

    public function test_late_failed_event_does_not_downgrade_successful_payment(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

        $invoice = $this->createInvoice($this->tenant);

        $invoice->forceFill([
            'currency' => 'INR',
            'total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'status' => 'open',
        ])->save();

        $capturedBody = json_encode([
            'entity' => 'event',
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_out_of_order',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'status' => 'captured',
                        'captured' => true,
                        'notes' => ['invoice_id' => $invoice->id],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $this->sendSignedRazorpayWebhook(
            $capturedBody,
            'evt-captured-first'
        )->assertOk();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(
            '100.00',
            number_format((float) $invoice->amount_paid, 2, '.', '')
        );
        $this->assertSame(
            '0.00',
            number_format((float) $invoice->amount_due, 2, '.', '')
        );

        // Simulate a delayed failure event for the same Razorpay payment.
        $failedBody = json_encode([
            'entity' => 'event',
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_out_of_order',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'status' => 'failed',
                        'captured' => false,
                        'error_code' => 'PAYMENT_FAILED',
                        'error_description' => 'Delayed failure event',
                        'notes' => ['invoice_id' => $invoice->id],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $this->sendSignedRazorpayWebhook(
            $failedBody,
            'evt-failed-late'
        )->assertOk();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(
            '100.00',
            number_format((float) $invoice->amount_paid, 2, '.', '')
        );
        $this->assertSame(
            '0.00',
            number_format((float) $invoice->amount_due, 2, '.', '')
        );

        $this->assertDatabaseHas('subscription_payments', [
            'provider' => 'razorpay',
            'provider_payment_id' => 'pay_out_of_order',
            'provider_event_id' => 'evt-captured-first',
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('subscription_payments', [
            'provider' => 'razorpay',
            'provider_payment_id' => 'pay_out_of_order',
            'provider_event_id' => 'evt-failed-late',
            'status' => 'failed',
        ]);
    }
}
