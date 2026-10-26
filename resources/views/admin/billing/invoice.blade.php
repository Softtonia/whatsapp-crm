<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 35px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #333;
        }
        h1 { color: #2563eb; font-size: 28px; }
        h2 { font-size: 16px; margin-top: 25px; }
        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th { background: #f1f5f9; text-align: left; }
        .right { text-align: right; }
        .meta td { width: 50%; border: none; }
        .totals { width: 55%; margin-left: auto; }
        .grand-total { font-weight: bold; background: #f1f5f9; }
        .due { font-weight: bold; }
        .footer {
            margin-top: 35px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #777;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>INVOICE</h1>
        <p>Subscription Billing Statement</p>
    </div>

    <table class="meta">
        <tr>
            <td>
                <strong>Invoice Number:</strong><br>
                {{ $invoice->invoice_number }}
            </td>
            <td>
                <strong>Status:</strong><br>
                {{ strtoupper($invoice->status) }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>Issue Date:</strong><br>
                {{ $invoice->issued_at?->format('d M Y') ?? 'N/A' }}
            </td>
            <td>
                <strong>Due Date:</strong><br>
                {{ $invoice->due_at?->format('d M Y') ?? 'N/A' }}
            </td>
        </tr>
    </table>

    <h2>Bill To</h2>
    <p>
        <strong>Tenant:</strong>
        {{ $invoice->tenant?->name ?? ('Tenant #' . $invoice->tenant_id) }}
        <br>
        <strong>Subscription Plan:</strong>
        {{ $invoice->subscription?->plan?->name ?? 'N/A' }}
    </p>

    <h2>Invoice Items</h2>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Quantity</th>
                <th class="right">Unit Price</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="right">
                        {{ $invoice->currency }} {{ number_format((float) $item->unit_price, 2) }}
                    </td>
                    <td class="right">
                        {{ $invoice->currency }} {{ number_format((float) $item->total, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No invoice items available.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Discount</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->discount_total, 2) }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->tax_total, 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Invoice Total</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        <tr>
            <td>Amount Paid</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->amount_paid, 2) }}</td>
        </tr>
        <tr class="due">
            <td>Amount Due</td>
            <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->amount_due, 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        This document was generated electronically.<br>
        Invoice {{ $invoice->invoice_number }}
    </div>
</body>
</html>
