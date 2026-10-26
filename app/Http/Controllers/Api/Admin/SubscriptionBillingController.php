<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionBillingController extends Controller
{
    protected function authorizePermission(Request $request, string ...$permissions): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->status && $user->status !== 'active') {
            abort(403, 'Account is inactive. Access denied.');
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists()) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission) || $user->hasPermissionTo($permission, 'web')) {
                return;
            }
        }

        abort(403, "You do not have the required permission: {$permissions[0]}.");
    }

    /**
     * List and filter subscription invoices.
     */
    public function invoices(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $filters = $request->validate([
            'tenant_id' => ['sometimes', 'integer', 'exists:tenants,id'],
            'subscription_id' => [
                'sometimes',
                'integer',
                'exists:tenant_subscriptions,id',
            ],
            'status' => ['sometimes', 'string', 'max:30'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = SubscriptionInvoice::query()
            ->with(['tenant', 'subscription.plan'])
            ->when(
                isset($filters['tenant_id']),
                fn($query) => $query->where(
                    'tenant_id',
                    $filters['tenant_id']
                )
            )
            ->when(
                isset($filters['subscription_id']),
                fn($query) => $query->where(
                    'subscription_id',
                    $filters['subscription_id']
                )
            )
            ->when(
                isset($filters['status']),
                fn($query) => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['currency']),
                fn($query) => $query->where(
                    'currency',
                    strtoupper($filters['currency'])
                )
            )
            ->when(
                $filters['from'] ?? null,
                fn($query, $date) => $query->whereDate(
                    'created_at',
                    '>=',
                    $date
                )
            )
            ->when(
                $filters['to'] ?? null,
                fn($query, $date) => $query->whereDate(
                    'created_at',
                    '<=',
                    $date
                )
            )
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($invoices);
    }

    /**
     * Show one invoice with its line items and payment history.
     */
    public function showInvoice(
        Request $request,
        SubscriptionInvoice $invoice
    ): JsonResponse {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $invoice->load([
            'tenant',
            'subscription.plan',
            'items',
            'payments',
        ]);

        return response()->json([
            'data' => $invoice,
        ]);
    }

    /**
     * List and filter payments.
     */
    public function payments(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $filters = $request->validate([
            'tenant_id' => ['sometimes', 'integer', 'exists:tenants,id'],
            'invoice_id' => [
                'sometimes',
                'integer',
                'exists:subscription_invoices,id',
            ],
            'status' => ['sometimes', 'string', 'max:30'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = SubscriptionPayment::query()
            ->with([
                'tenant',
                'invoice:id,invoice_number,status,total,amount_paid,amount_due,currency',
            ])
            ->when(
                isset($filters['tenant_id']),
                fn($query) => $query->where(
                    'tenant_id',
                    $filters['tenant_id']
                )
            )
            ->when(
                isset($filters['invoice_id']),
                fn($query) => $query->where(
                    'invoice_id',
                    $filters['invoice_id']
                )
            )
            ->when(
                isset($filters['status']),
                fn($query) => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['provider']),
                fn($query) => $query->where(
                    'provider',
                    $filters['provider']
                )
            )
            ->when(
                $filters['from'] ?? null,
                fn($query, $date) => $query->whereDate(
                    'created_at',
                    '>=',
                    $date
                )
            )
            ->when(
                $filters['to'] ?? null,
                fn($query, $date) => $query->whereDate(
                    'created_at',
                    '<=',
                    $date
                )
            )
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($payments);
    }

    /**
     * Show payment details, including failure information.
     */
    public function showPayment(
        Request $request,
        SubscriptionPayment $payment
    ): JsonResponse {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $payment->load([
            'tenant',
            'invoice.items',
        ]);

        return response()->json([
            'data' => $payment,
        ]);
    }

    /**
     * List payments for a particular invoice.
     */
    public function invoicePayments(
        Request $request,
        SubscriptionInvoice $invoice
    ): JsonResponse {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:30'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = $invoice->payments()
            ->with('tenant')
            ->when(
                isset($filters['status']),
                fn($query) => $query->where(
                    'status',
                    $filters['status']
                )
            )
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($payments);
    }
    /**
     * Download a subscription invoice as PDF.
     */
    public function downloadInvoice(Request $request, SubscriptionInvoice $invoice)
    {
        $this->authorizePermission($request, 'billing.read', 'tenants.billing.view');
        $invoice->load([
            'tenant',
            'subscription.plan',
            'items',
            'payments',
        ]);

        $pdf = Pdf::loadView('admin.billing.invoice', [
            'invoice' => $invoice,
        ])->setPaper('a4');

        return $pdf->download(
            'invoice-' . $invoice->invoice_number . '.pdf'
        );
    }
}
