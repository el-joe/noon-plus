@php
    /**
     * Delivery agent payslip (HTML for the panel, A4 PDF through dompdf).
     * Same table-only layout rules as invoices/order-pdf: in Arabic the
     * columns are reversed and cells right-aligned.
     *
     * @var \App\Domains\Delivery\Models\DeliveryPayslip $payslip
     * @var \App\Domains\Countries\Models\Country $country
     * @var bool $forPdf
     * @var \Closure $money
     */
    $rtl = app()->getLocale() === 'ar';
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $row = fn (array $cells): array => $rtl ? array_reverse($cells) : $cells;
    $details = [
        [__('Payslip'), $payslip->number],
        [__('Agent'), $payslip->agent->name],
        [__('Paid by'), $payslip->agent->carrier?->name ?? __('Nawy fleet')],
        [__('Period'), $payslip->period_start->toDateString().' – '.$payslip->period_end->toDateString()],
        [__('Issued'), $payslip->issued_at->setTimezone($country->timezone)->format('Y-m-d H:i')],
        [__('Rate per delivery'), $money($payslip->per_stop)],
        [__('Rate per failed attempt'), $money($payslip->per_failed ?? '0')],
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payslip') }} {{ $payslip->number }}</title>
    <style>
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: normal; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: bold; src: url('{{ resource_path('fonts/inter/Inter-Variable.ttf') }}') format('truetype'); }
        @page { margin: 32px 36px; }
        body { font-family: 'Inter', 'DejaVu Sans', sans-serif; color: #101828; font-size: 11px; line-height: 1.45; {{ $forPdf ? '' : 'max-width: 760px; margin: 24px auto;' }} }
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
        .details .label { width: 160px; color: #667085; }
        .grand td { font-weight: bold; font-size: 13px; border-top: 2px solid #101828; }
    </style>
</head>
<body>
    <h1>{{ __('Payslip') }}</h1>
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

    <table class="lines">
        <thead>
            <tr>
                @foreach ($row([['class' => '', 'text' => __('Date')], ['class' => '', 'text' => __('Assignment')], ['class' => '', 'text' => __('Order')], ['class' => '', 'text' => __('Outcome')], ['class' => 'num', 'text' => __('Amount')]]) as $cell)
                    <th class="{{ $cell['class'] }}">{{ $cell['text'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($payslip->lines as $line)
                <tr>
                    @foreach ($row([
                        ['class' => '', 'text' => $line->finished_at->setTimezone($country->timezone)->format('Y-m-d H:i')],
                        ['class' => '', 'text' => $line->assignment?->number],
                        ['class' => '', 'text' => $line->assignment?->subOrder?->number],
                        ['class' => '', 'text' => $line->outcome === 'delivered' ? __('Delivered') : __('Failed attempt')],
                        ['class' => 'num', 'text' => $money($line->amount)],
                    ]) as $cell)
                        <td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 14px;">
        <tr>@foreach ($row([['class' => '', 'text' => __('Deliveries')], ['class' => 'num', 'text' => (string) $payslip->delivered]]) as $cell)<td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>@endforeach</tr>
        <tr>@foreach ($row([['class' => '', 'text' => __('Failed attempts')], ['class' => 'num', 'text' => (string) $payslip->failed]]) as $cell)<td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>@endforeach</tr>
        <tr class="grand">@foreach ($row([['class' => '', 'text' => __('Total credited to your wallet')], ['class' => 'num', 'text' => $money($payslip->total)]]) as $cell)<td class="{{ $cell['class'] }}">{{ $cell['text'] }}</td>@endforeach</tr>
    </table>
</body>
</html>
