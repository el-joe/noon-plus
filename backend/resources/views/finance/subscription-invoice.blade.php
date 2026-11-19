@php
    /**
     * Vendor subscription invoice (FR-FIN-7), rendered for the browser and
     * as an A4 PDF by dompdf.
     *
     * @var \App\Domains\Finance\Models\VendorSubscriptionInvoice $invoice
     * @var \App\Domains\Countries\Models\Country $country
     */
    $rtl = app()->getLocale() === 'ar';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice') }} {{ $invoice->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #111; font-size: 12px; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: {{ $rtl ? 'right' : 'left' }}; }
        .amount { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .total td { font-weight: bold; border-top: 2px solid #111; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #111; border-radius: 4px; text-transform: uppercase; font-size: 10px; }
    </style>
</head>
<body>
    <table style="margin-top:0"><tr>
        <td style="border:0"><h1>Nawy</h1><span class="muted">{{ __('Seller plan invoice') }} · {{ $country->name_en }}</span></td>
        <td style="border:0" class="amount"><strong>{{ $invoice->number }}</strong><br><span class="status">{{ \App\Shared\Localization\Label::of($invoice->status) }}</span></td>
    </tr></table>

    <table>
        <tr><th>{{ __('Seller') }}</th><td>{{ $invoice->vendor?->store_name }} (V-{{ $invoice->vendor_id }})</td></tr>
        <tr><th>{{ __('Plan') }}</th><td>{{ $invoice->plan?->name() }}</td></tr>
        <tr><th>{{ __('Period') }}</th><td>{{ $invoice->period_start->setTimezone($country->timezone)->format('j M Y') }} – {{ $invoice->period_end->setTimezone($country->timezone)->format('j M Y') }}</td></tr>
        <tr><th>{{ __('Issued') }}</th><td>{{ $invoice->created_at?->setTimezone($country->timezone)->format('j M Y') }}</td></tr>
        <tr><th>{{ __('Due') }}</th><td>{{ $invoice->due_at->setTimezone($country->timezone)->format('j M Y') }}</td></tr>
        <tr><th>{{ __('Paid') }}</th><td>{{ $invoice->paid_at ? $invoice->paid_at->setTimezone($country->timezone)->format('j M Y').' · '.\App\Shared\Localization\Label::of($invoice->paid_via) : '—' }}</td></tr>
        @if ($invoice->charge_currency)
            <tr><th>{{ __('Charged by the gateway') }}</th><td>{{ rtrim(rtrim((string) $invoice->charge_amount, '0'), '.') }} {{ $invoice->charge_currency }} · {{ __('1 :from = :rate :to', ['from' => $invoice->currency, 'rate' => rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.'), 'to' => $invoice->charge_currency]) }}</td></tr>
        @endif
    </table>

    <table>
        <tr><th>{{ __('Description') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
        <tr><td>{{ __('Subscription') }} — {{ $invoice->plan?->name() }}</td><td class="amount">{{ $net }}</td></tr>
        <tr><td>{{ $country->tax_name ?? __('VAT') }} ({{ $country->tax_rate }}%)</td><td class="amount">{{ $vat }}</td></tr>
        <tr class="total"><td>{{ __('Total') }}</td><td class="amount">{{ $total }}</td></tr>
    </table>
</body>
</html>
