@php
    /**
     * FBN inbound carton labels: one per carton, the plan reference as a
     * Code 39 barcode drawn with table cells (prints from the browser and
     * renders in dompdf as A6 pages).
     *
     * @var \App\Domains\Fulfilment\Models\InboundShipment $shipment
     * @var int $cartons
     * @var array<int, array{0: bool, 1: int}> $bars
     * @var bool $forPdf
     */
    $rtl = app()->getLocale() === 'ar';
    $skus = $shipment->lines->map(fn ($line) => $line->listing->variant->sku.' × '.$line->quantity_expected)->implode(', ');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Inbound labels') }} {{ $shipment->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #111; margin: 0; font-size: 11px; }
        .label { border: 2px solid #111; padding: 10px; margin: {{ $forPdf ? '0' : '12px' }}; width: {{ $forPdf ? 'auto' : '380px' }}; page-break-after: always; }
        .label:last-child { page-break-after: auto; }
        .brand { font-size: 14px; font-weight: bold; }
        .ref { font-size: 16px; font-weight: bold; letter-spacing: 1px; margin: 6px 0; }
        .muted { color: #555; }
        table.bars { border-collapse: collapse; margin: 6px 0; }
        table.bars td { height: 44px; padding: 0; }
        .carton { font-size: 22px; font-weight: bold; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .row { width: 100%; border-collapse: collapse; }
        .row td { vertical-align: top; padding: 2px 0; }
        @media print { .label { margin: 0 0 8px; } }
    </style>
</head>
<body>
@for ($carton = 1; $carton <= $cartons; $carton++)
    <div class="label" data-carton="{{ $carton }}">
        <table class="row"><tr>
            <td><span class="brand">{{ __('Fulfilled by Nawy') }}</span><br><span class="muted">{{ __('Inbound shipment') }}</span></td>
            <td class="carton">{{ __('Carton :number of :total', ['number' => $carton, 'total' => $cartons]) }}</td>
        </tr></table>
        <div class="ref">{{ $shipment->number }}</div>
        <table class="bars"><tr>
            @foreach ($bars as [$isBar, $width])
                <td style="width: {{ $width * 2 }}px; background: {{ $isBar ? '#000' : '#fff' }};"></td>
            @endforeach
        </tr></table>
        <table class="row">
            <tr><td class="muted" style="width: 30%">{{ __('Ship to') }}</td><td><strong>{{ $shipment->warehouse->code }} · {{ $shipment->warehouse->name() }}</strong><br>{{ $shipment->warehouse->address }} {{ $shipment->warehouse->city }}</td></tr>
            <tr><td class="muted">{{ __('From') }}</td><td>{{ $shipment->vendor->store_name }}</td></tr>
            <tr><td class="muted">{{ __('Contents') }}</td><td>{{ $skus }}</td></tr>
            @if ($shipment->expected_at)
                <tr><td class="muted">{{ __('Expected') }}</td><td>{{ $shipment->expected_at->toDateString() }}</td></tr>
            @endif
        </table>
    </div>
@endfor
@unless ($forPdf)
    <script>window.addEventListener('load', () => window.print());</script>
@endunless
</body>
</html>
