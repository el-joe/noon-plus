@props(['amount', 'currency'])

<span {{ $attributes->class('whitespace-nowrap tabular-nums') }}>{{ app(\App\Shared\Money\MoneyFormatter::class)->format($amount, $currency) }}</span>
