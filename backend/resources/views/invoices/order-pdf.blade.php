@php
    /**
     * D16: the tax invoice as a PDF (dompdf). Same content as invoices/order.blade.php,
     * laid out for dompdf: tables only, Inter for Latin text with DejaVu Sans
     * (bundled with dompdf) as the per-glyph fallback that shapes Arabic. dompdf
     * does not reorder table columns for `direction: rtl`, so in Arabic the
     * columns are reversed here and every cell is right-aligned.
     *
     * @var \App\Domains\Orders\Models\Order $order
     * @var \Illuminate\Support\Collection<int, \App\Domains\Orders\Models\SubOrder> $subOrders
     * @var array<int, array{label: string, amount: string, grand?: bool, negative?: bool}> $totals
     */
    $rtl = app()->getLocale() === 'ar';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $row = fn (array $cells): array => $rtl ? array_reverse($cells) : $cells;
    $address = collect($order->shipping_address ?? [])->except(['address_id', 'latitude', 'longitude'])->filter(fn ($value) => is_scalar($value) && $value !== '');
    $details = [
        [__('Order'), $order->number],
        [__('Date'), $order->placed_at->setTimezone($country->timezone)->toDateString()],
        [__('Customer'), trim($customer->name.' · '.$customer->email, ' ·')],
        [__('Delivery address'), $address->implode(', ')],
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Tax invoice') }} {{ $order->number }}</title>
    <style>
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: normal; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: bold; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @page { margin: 32px 36px; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; color: #101828; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0 0 2px; text-align: {{ $start }}; }
        p { margin: 0; text-align: {{ $start }}; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; text-align: {{ $start }}; vertical-align: top; }
        .lines { margin-top: 14px; }
        .lines th { background: #f2f4f7; font-size: 10px; color: #344054; }
        .lines td { border-bottom: 1px solid #eaecf0; }
        .num { text-align: {{ $end }}; white-space: nowrap; }
        .muted { color: #667085; }
        .details td { padding: 2px 8px; }
        .details .label { width: 120px; color: #667085; }
        .totals { margin-top: 14px; width: 55%; {{ $rtl ? 'margin-right: 45%;' : 'margin-left: 45%;' }} }
        .totals td { padding: 3px 8px; }
        .grand td { font-weight: bold; font-size: 13px; border-top: 2px solid #101828; }
        .footer { margin-top: 18px; }
    </style>
</head>
<body>
    <h1>{{ __('Tax invoice') }}</h1>
    <p class="muted">Nawy · {{ $rtl ? $country->name_ar : $country->name_en }}</p>

    <table class="details" style="margin-top: 12px;">
        @foreach ($details as [$label, $value])
            <tr>
                @foreach ($row([['class' => 'label', 'text' => $label], ['class' => '', 'text' => $value]]) as $cell)
                    <td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @foreach ($subOrders as $subOrder)
        <table class="lines">
            <thead>
                <tr>
                    <th colspan="4">{{ __('Shipment') }} {{ $subOrder->number }} — {{ __('Sold by') }} {{ $subOrder->vendor?->store_name }}</th>
                </tr>
                <tr>
                    @foreach ($row([['num' => false, 'text' => __('Item')], ['num' => true, 'text' => __('Qty')], ['num' => true, 'text' => __('Unit price')], ['num' => true, 'text' => __('Total')]]) as $cell)
                        <th class="{{ $cell['num'] ? 'num' : '' }}">{{ $cell['text'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($subOrder->items as $item)
                    <tr>
                        @foreach ($row([
                            ['num' => false, 'text' => $item->title, 'sku' => $item->sku],
                            ['num' => true, 'text' => (string) $item->quantity],
                            ['num' => true, 'text' => $money((string) $item->unit_price)],
                            ['num' => true, 'text' => $money((string) $item->line_total)],
                        ]) as $cell)
                            <td class="{{ $cell['num'] ? 'num' : '' }}">{{ $cell['text'] }}@if (! empty($cell['sku']))<br><span class="muted">{{ $cell['sku'] }}</span>@endif</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <table class="totals">
        @foreach ($totals as $line)
            <tr class="{{ ! empty($line['grand']) ? 'grand' : '' }}">
                @foreach ($row([['num' => false, 'text' => $line['label']], ['num' => true, 'text' => (! empty($line['negative']) ? '−' : '').$money((string) $line['amount'])]]) as $cell)
                    <td class="{{ $cell['num'] ? 'num' : '' }} {{ ! empty($line['muted']) ? 'muted' : '' }}">{{ $cell['text'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
    <p class="muted footer">{{ __('Prices include VAT.') }}</p>
</body>
</html>
