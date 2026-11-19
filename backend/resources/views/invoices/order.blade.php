@php
    /** G-A6: printable tax invoice (HTML; print / save as PDF from the browser or app). */
    $currency = $order->currency;
    $money = fn ($amount) => $currency.' '.number_format((float) $amount, in_array($currency, ['OMR', 'KWD', 'BHD', 'JOD'], true) ? 3 : 2);
    $rtl = app()->getLocale() === 'ar';
    $address = collect($order->shipping_address ?? [])->except(['address_id', 'latitude', 'longitude'])->filter(fn ($value) => is_scalar($value) && $value !== '');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Tax invoice') }} {{ $order->number }}</title>
    <style>
        body { font-family: Inter, Cairo, Arial, sans-serif; color: #101828; margin: 24px; font-size: 14px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px; border-bottom: 1px solid #eaecf0; text-align: start; }
        td.num, th.num { text-align: end; white-space: nowrap; }
        .muted { color: #667085; }
        .totals td { border: 0; padding: 4px 8px; }
        .grand td { font-weight: 700; border-top: 2px solid #101828; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>{{ __('Tax invoice') }}</h1>
    <p class="muted">Nawy · {{ $rtl ? $country->name_ar : $country->name_en }}</p>
    <p>
        <strong>{{ __('Order') }}:</strong> {{ $order->number }}<br>
        <strong>{{ __('Date') }}:</strong> {{ $order->placed_at->setTimezone($country->timezone)->toDateString() }}<br>
        <strong>{{ __('Customer') }}:</strong> {{ $customer->name }} · {{ $customer->email }}<br>
        <strong>{{ __('Delivery address') }}:</strong> {{ $address->implode(', ') }}
    </p>

    @foreach ($order->subOrders as $subOrder)
        <table>
            <thead>
                <tr><th colspan="4">{{ __('Shipment') }} {{ $subOrder->number }} · {{ __('Sold by') }} {{ $subOrder->vendor?->store_name }}</th></tr>
                <tr><th>{{ __('Item') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Total') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($subOrder->items as $item)
                    <tr><td>{{ $item->title }} <span class="muted">{{ $item->sku }}</span></td><td class="num">{{ $item->quantity }}</td><td class="num">{{ $money($item->unit_price) }}</td><td class="num">{{ $money($item->line_total) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <table class="totals">
        <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
        @if ((float) $order->discount_total > 0)<tr><td>{{ __('Discount') }}</td><td class="num">−{{ $money($order->discount_total) }}</td></tr>@endif
        <tr><td>{{ __('Shipping') }}</td><td class="num">{{ $money($order->shipping_total) }}</td></tr>
        @if ((float) $order->cod_fee > 0)<tr><td>{{ __('Cash on delivery fee') }}</td><td class="num">{{ $money($order->cod_fee) }}</td></tr>@endif
        @if ((float) $order->warranty_total > 0)<tr><td>{{ __('Warranty') }}</td><td class="num">{{ $money($order->warranty_total) }}</td></tr>@endif
        <tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ $money($order->grand_total) }}</td></tr>
        <tr><td class="muted">{{ __('VAT included (:rate%)', ['rate' => rtrim(rtrim((string) $order->tax_rate, '0'), '.')]) }}</td><td class="num muted">{{ $money($order->tax_included) }}</td></tr>
    </table>
    <p class="muted">{{ __('Prices include VAT.') }}</p>
</body>
</html>
