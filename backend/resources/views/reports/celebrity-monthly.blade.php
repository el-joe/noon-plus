@php
    /**
     * FR-ANL-2: celebrity monthly report (dompdf PDF and printable HTML).
     * Same dompdf rules as invoices/order-pdf: tables only, Inter with DejaVu
     * Sans as the Arabic fallback, columns reversed in Arabic.
     *
     * @var \App\Domains\Marketers\Models\CelebrityMonthlyReport $report
     */
    $rtl = app()->getLocale() === 'ar';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $row = fn (array $cells): array => $rtl ? array_reverse($cells) : $cells;
    $metrics = $report->metrics ?? [];
    $month = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $report->period.'-01')->locale(app()->getLocale())->translatedFormat('F Y');
    $figures = [
        [__('Orders'), (string) $report->orders_count],
        [__('Units sold'), (string) $report->units_sold],
        [__('Attributed sales'), $money((string) $report->attributed_sales)],
        [__('Conversions'), (string) $report->conversions_count],
        [__('Referral clicks'), (string) $report->referral_clicks],
        [__('Net commission'), $money((string) $report->commission_net)],
        [__('Bonuses'), $money((string) $report->bonus_total)],
        [__('Fixed fees'), $money((string) $report->fixed_fees)],
        [__('Total earnings'), $money((string) $report->total_earnings)],
        [__('Reversed commission'), $money((string) ($metrics['reversed_commission'] ?? '0'))],
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Creator monthly report') }} {{ $report->period }}</title>
    <style>
        @if ($forPdf)
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: normal; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: bold; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @page { margin: 32px 36px; }
        @endif
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; color: #101828; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0 0 2px; text-align: {{ $start }}; }
        h2 { font-size: 13px; margin: 16px 0 4px; text-align: {{ $start }}; }
        p { margin: 0; text-align: {{ $start }}; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 8px; text-align: {{ $start }}; vertical-align: top; border-bottom: 1px solid #eaecf0; }
        th { background: #f2f4f7; font-size: 10px; color: #344054; }
        .num { text-align: {{ $end }}; white-space: nowrap; }
        .muted { color: #667085; }
    </style>
</head>
<body>
    <h1>{{ __('Creator monthly report') }}</h1>
    <p class="muted">Nawy · {{ $rtl ? $country->name_ar : $country->name_en }} · {{ $month }}</p>
    <p><strong>{{ $marketer?->display_name }}</strong> · {{ __('Generated (Oman)') }} {{ $report->generated_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</p>

    <h2>{{ __('Summary') }}</h2>
    <table>
        @foreach ($figures as [$label, $value])
            <tr>@foreach ($row([$label, $value]) as $index => $cell)<td class="{{ ($rtl ? $index === 0 : $index === 1) ? 'num' : '' }}">{{ $cell }}</td>@endforeach</tr>
        @endforeach
    </table>

    <h2>{{ __('Top products') }}</h2>
    <table>
        <tr>@foreach ($row([__('Product'), __('Units'), __('Sales')]) as $cell)<th>{{ $cell }}</th>@endforeach</tr>
        @forelse ($metrics['top_products'] ?? [] as $product)
            <tr>@foreach ($row([$product['title'], (string) $product['units'], $money((string) $product['sales'])]) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('No attributed sales this month.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Conversions by status') }}</h2>
    <table>
        @forelse ($metrics['conversions_by_status'] ?? [] as $status => $count)
            <tr>@foreach ($row([\App\Shared\Localization\Label::of($status), (string) $count]) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
            <tr><td class="muted">{{ __('No conversions this month.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Campaigns') }}</h2>
    <table>
        @foreach ([[__('Invitations received'), $metrics['campaigns']['invitations'] ?? 0], [__('Invitations accepted'), $metrics['campaigns']['accepted'] ?? 0], [__('Active campaigns'), $metrics['campaigns']['active'] ?? 0], [__('Content approved'), $metrics['campaigns']['content_approved'] ?? 0]] as [$label, $value])
            <tr>@foreach ($row([$label, (string) $value]) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @endforeach
    </table>

    <h2>{{ __('Monthly obligations') }}</h2>
    <table>
        <tr>@foreach ($row([__('Policy'), __('Required'), __('Completed'), __('Status')]) as $cell)<th>{{ $cell }}</th>@endforeach</tr>
        @forelse ($metrics['cross_promotion'] ?? [] as $obligation)
            <tr>@foreach ($row([$obligation['policy'], (string) $obligation['required'], (string) $obligation['completed'], \App\Shared\Localization\Label::of($obligation['status'])]) as $cell)<td>{{ $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('No cross-promotion obligation this month.') }}</td></tr>
        @endforelse
    </table>
</body>
</html>
