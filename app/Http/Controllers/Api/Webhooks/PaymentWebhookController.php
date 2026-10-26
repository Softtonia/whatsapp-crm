<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $provider): JsonResponse
    {
        if (strtolower($provider) !== 'razorpay') {
            return response()->json([
                'message' => 'Unsupported payment provider.',
            ], 404);
        }

        $secret = class_exists(\App\Services\PlatformSettingService::class)
            ? app(\App\Services\PlatformSettingService::class)->getWebhookSecret('razorpay')
            : config('services.razorpay.webhook_secret');
        $secret = $secret ?: config('services.razorpay.webhook_secret');

        if (!is_string($secret) || $secret === '') {
            return response()->json([
                'message' => 'Razorpay webhook secret is not configured.',
            ], 503);
        }

        // Razorpay requires signature verification over the raw body.
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $eventId = (string) $request->header('x-razorpay-event-id', '');

        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        if (
            $signature === '' ||
            !preg_match('/^[a-f0-9]{64}$/i', $signature) ||
            !hash_equals($expectedSignature, strtolower($signature))
        ) {
            return response()->json([
                'message' => 'Invalid Razorpay webhook signature.',
            ], 401);
        }

        if ($eventId === '' || strlen($eventId) > 255) {
            return response()->json([
                'message' => 'Missing or invalid Razorpay event ID.',
            ], 400);
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
            return response()->json([
                'message' => 'Malformed Razorpay webhook payload.',
            ], 400);
        }

        $event = $payload['event'];

        if (!in_array($event, [
            'payment.captured',
            'order.paid',
            'payment.failed',
        ], true)) {
            // Ignore valid but unsupported events without changing billing state.
            return response()->json([
                'message' => 'Event ignored.',
                'event' => $event,
            ]);
        }

        $payment = data_get($payload, 'payload.payment.entity', []);
        $order = data_get($payload, 'payload.order.entity', []);

        if (!is_array($payment)) {
            $payment = [];
        }

        if (!is_array($order)) {
            $order = [];
        }

        $paymentId = $payment['id'] ?? null;
        $amountPaise = $payment['amount'] ?? null;
        $currency = $payment['currency'] ?? null;

        // order.paid contains both order and payment entities.
        // payment.captured/payment.failed contain the payment entity.
        $invoiceId = data_get($order, 'notes.invoice_id')
            ?? data_get($payment, 'notes.invoice_id');

        if (
            !is_string($paymentId) ||
            $paymentId === '' ||
            !is_numeric($amountPaise) ||
            (float) $amountPaise <= 0 ||
            !is_string($currency) ||
            !preg_match('/^[A-Za-z]{3}$/', $currency) ||
            !filter_var($invoiceId, FILTER_VALIDATE_INT) ||
            (int) $invoiceId < 1
        ) {
            return response()->json([
                'message' => 'Required invoice/payment details are missing.',
            ], 422);
        }

        $amount = round(((float) $amountPaise) / 100, 2);
        $currency = strtoupper($currency);
        $isSuccess = in_array($event, [
            'payment.captured',
            'order.paid',
        ], true);

        if (
            $isSuccess &&
            ($payment['status'] ?? null) !== 'captured'
        ) {
            return response()->json([
                'message' => 'Payment is not captured.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use (
                $eventId,
                $event,
                $paymentId,
                $amount,
                $currency,
                $invoiceId,
                $isSuccess,
                $payment
            ): array {
                if (
                    SubscriptionPayment::where('provider_event_id', $eventId)
                        ->exists()
                ) {
                    return [
                        'status' => 200,
                        'body' => [
                            'message' => 'Duplicate event ignored.',
                            'duplicate' => true,
                        ],
                    ];
                }

                $invoice = SubscriptionInvoice::query()
                    ->whereKey((int) $invoiceId)
                    ->lockForUpdate()
                    ->first();

                if (!$invoice) {
                    return [
                        'status' => 404,
                        'body' => ['message' => 'Invoice not found.'],
                    ];
                }

                if (strtoupper($invoice->currency) !== $currency) {
                    return [
                        'status' => 422,
                        'body' => ['message' => 'Invoice currency mismatch.'],
                    ];
                }

                $existingSuccess = SubscriptionPayment::query()
                    ->where('provider', 'razorpay')
                    ->where('provider_payment_id', $paymentId)
                    ->where('invoice_id', $invoice->id)
                    ->whereIn('status', ['succeeded', 'captured', 'paid'])
                    ->exists();

                $amountDue = round((float) $invoice->amount_due, 2);

                // Compare a new charge with the current outstanding balance.
                // For a late event on an already-paid invoice, compare with
                // the recorded successful payment, if one exists.
                $expectedAmount = $amountDue > 0
                    ? $amountDue
                    : $this->previousSuccessfulAmount($invoice, $paymentId);

                if ($expectedAmount === null) {
                    return [
                        'status' => 409,
                        'body' => [
                            'message' => 'Invoice has no outstanding balance.',
                        ],
                    ];
                }

                if (abs($amount - $expectedAmount) > 0.009) {
                    return [
                        'status' => 422,
                        'body' => [
                            'message' => 'Payment amount mismatch.',
                            'expected_amount' => number_format($expectedAmount, 2, '.', ''),
                            'received_amount' => number_format($amount, 2, '.', ''),
                            'currency' => $currency,
                        ],
                    ];
                }

                // Store each distinct Razorpay event. The DB unique constraint
                // on provider_event_id protects against concurrent duplicates.
                SubscriptionPayment::create([
                    'invoice_id' => $invoice->id,
                    'tenant_id' => $invoice->tenant_id,
                    'provider' => 'razorpay',
                    'provider_payment_id' => $paymentId,
                    'provider_event_id' => $eventId,
                    'transaction_type' => 'charge',
                    'status' => $isSuccess ? 'succeeded' : 'failed',
                    'amount' => $amount,
                    'currency' => $currency,
                    'failure_code' => $isSuccess
                        ? null
                        : ($payment['error_code'] ?? null),
                    'failure_message' => $isSuccess
                        ? null
                        : ($payment['error_description'] ?? null),
                    'paid_at' => $isSuccess ? now() : null,
                    'reconciled_at' => $isSuccess ? now() : null,
                    'metadata' => [
                        'razorpay_event' => $event,
                        'razorpay_order_id' => $payment['order_id'] ?? null,
                        'method' => $payment['method'] ?? null,
                    ],
                ]);

                if (!$isSuccess) {
                    // A late failure event must never downgrade a successful
                    // payment or mark the invoice as unpaid.
                    return [
                        'status' => 200,
                        'body' => [
                            'message' => $existingSuccess
                                ? 'Late failure ignored; payment was already successful.'
                                : 'Failed payment recorded.',
                            'invoice_updated' => false,
                        ],
                    ];
                }

                // A different event ID can still refer to the same payment.
                // Record it, but never apply the same payment twice.
                if ($existingSuccess) {
                    return [
                        'status' => 200,
                        'body' => [
                            'message' => 'Payment was already reconciled.',
                            'duplicate_payment' => true,
                            'invoice_updated' => false,
                        ],
                    ];
                }

                $invoice->amount_paid = round(
                    (float) $invoice->amount_paid + $amount,
                    2
                );

                $invoice->amount_due = max(
                    0,
                    round(
                        (float) $invoice->total - (float) $invoice->amount_paid,
                        2
                    )
                );

                $invoice->status = $invoice->amount_due <= 0
                    ? 'paid'
                    : 'partially_paid';

                if ($invoice->amount_due <= 0) {
                    $invoice->amount_due = 0;
                    $invoice->paid_at = now();
                }

                $invoice->save();

                if ($invoice->amount_due <= 0 && $invoice->subscription_id) {
                    $subscription = $invoice->subscription()
                        ->lockForUpdate()
                        ->first();

                    if ($subscription) {
                        $updates = [];

                        if ($invoice->billing_period_start) {
                            $updates['billing_period_starts_at'] =
                                $invoice->billing_period_start;
                        }

                        if ($invoice->billing_period_end) {
                            $updates['billing_period_ends_at'] =
                                $invoice->billing_period_end;
                            $updates['next_billing_at'] =
                                $invoice->billing_period_end;
                        }

                        if (in_array(
                            $subscription->status,
                            ['pending', 'past_due', 'payment_failed'],
                            true
                        )) {
                            $updates['status'] = 'active';
                        }

                        if ($updates !== []) {
                            $subscription->forceFill($updates)->save();
                        }
                    }
                }

                return [
                    'status' => 200,
                    'body' => [
                        'message' => 'Razorpay payment reconciled.',
                        'invoice_id' => $invoice->id,
                        'invoice_status' => $invoice->status,
                        'amount_paid' => $invoice->amount_paid,
                        'amount_due' => $invoice->amount_due,
                    ],
                ];
            });

            return response()->json($result['body'], $result['status']);
        } catch (QueryException $exception) {
            // Handles a duplicate event racing with another webhook request.
            if (
                SubscriptionPayment::where('provider_event_id', $eventId)->exists()
            ) {
                return response()->json([
                    'message' => 'Duplicate event ignored.',
                    'duplicate' => true,
                ]);
            }

            throw $exception;
        }
    }

    private function previousSuccessfulAmount(
        SubscriptionInvoice $invoice,
        string $paymentId
    ): ?float {
        $payment = SubscriptionPayment::query()
            ->where('provider', 'razorpay')
            ->where('provider_payment_id', $paymentId)
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['succeeded', 'captured', 'paid'])
            ->first();

        return $payment ? round((float) $payment->amount, 2) : null;
    }
}
