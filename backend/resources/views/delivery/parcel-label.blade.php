@php
    /**
     * Parcel label (A6): the shipment's barcode payload as Code 39 (table
     * cells, prints from the browser and in dompdf), the shipment number,
     * ship-to, seller, sub-order and COD amount.
     *
     * @var \App\Domains\Delivery\Models\Shipment $shipment
     * @var string $payload
     * @var array<int, array{0: bool, 1: int}> $bars
     * @var \Illuminate\Support\Collection<string, string> $address
     * @var string|null $cod
     * @var bool $forPdf
     */
    $rtl = app()->getLocale() === 'ar';
    $subOrder = $shipment->subOrder;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Parcel label') }} {{ $shipment->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #111; margin: 0; font-size: 11px; }
        .label { border: 2px solid #111; padding: 10px; margin: {{ $forPdf ? '0' : '12px' }}; width: {{ $forPdf ? 'auto' : '380px' }}; }
        .brand { font-size: 14px; font-weight: bold; }
        .ref { font-size: 16px; font-weight: bold; letter-spacing: 1px; margin: 6px 0 2px; }
        .payload { font-size: 10px; letter-spacing: 1px; text-align: center; }
        .muted { color: #555; }
        table.bars { border-collapse: collapse; margin: 6px auto 2px; }
        table.bars td { height: 52px; padding: 0; }
        .cod { font-size: 18px; font-weight: bold; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .row { width: 100%; border-collapse: collapse; }
        .row td { vertical-align: top; padding: 2px 0; }
    </style>
</head>
<body>
    <div class="label">
        <table class="row"><tr>
            <td><span class="brand">Nawy</span><br><span class="muted">{{ $shipment->carrier?->name ?? __('Nawy fleet') }}</span></td>
            <td class="cod">{{ $cod === null ? __('Prepaid') : __('COD :amount', ['amount' => $cod]) }}</td>
        </tr></table>
        <div class="ref" dir="ltr">{{ $shipment->number }}</div>
        <table class="bars" data-barcode="{{ $payload }}"><tr>
            @foreach ($bars as [$isBar, $width])
                <td style="width: {{ $width * 2 }}px; background: {{ $isBar ? '#000' : '#fff' }};"></td>
            @endforeach
        </tr></table>
        <div class="payload" dir="ltr">*{{ $payload }}*</div>
        <table class="row" style="margin-top: 8px;">
            <tr><td class="muted" style="width: 30%">{{ __('Ship to') }}</td><td><strong>{{ $address->get('name') }}</strong><br>{{ $address->except(['name', 'phone'])->implode(', ') }}<br><span dir="ltr">{{ $address->get('phone') }}</span></td></tr>
            <tr><td class="muted">{{ __('From') }}</td><td>{{ $subOrder->vendor?->store_name }}</td></tr>
            <tr><td class="muted">{{ __('Order') }}</td><td dir="ltr">{{ $subOrder->number }}</td></tr>
            <tr><td class="muted">{{ __('Items') }}</td><td>{{ $subOrder->items->sum('quantity') }}</td></tr>
        </table>
    </div>
@unless ($forPdf)
    <script>window.addEventListener('load', () => window.print());</script>
@endunless
</body>
</html>
