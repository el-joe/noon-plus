<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Packing slip :number', ['number' => $subOrder->number]) }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none; } body { background: #fff; } }</style>
</head>
<body class="bg-surface font-sans text-fg">
<main class="mx-auto flex max-w-3xl flex-col gap-6 p-8">
    <div class="no-print flex justify-end">
        <x-ui.button type="button" onclick="window.print()">{{ __('Print') }}</x-ui.button>
    </div>
    <header class="flex items-start justify-between border-b border-line pb-4">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Packing slip') }}</h1>
            <p class="text-sm text-fg-secondary">{{ $vendor->store_name }} · {{ $country->name_en }}</p>
        </div>
        <div class="text-end text-sm">
            <p class="font-semibold">{{ $subOrder->number }}</p>
            <p class="text-fg-secondary">{{ $subOrder->order->placed_at->setTimezone($country->timezone)->format('j M Y, H:i') }}</p>
        </div>
    </header>
    @php($address = $subOrder->order->shipping_address)
    <section class="text-sm">
        <h2 class="font-semibold">{{ __('Ship to') }}</h2>
        <p>{{ $address['name'] ?? '' }}</p>
        <p>{{ collect([$address['street'] ?? null, $address['area'] ?? null, $address['city'] ?? null])->filter()->implode(', ') }}</p>
        <p>{{ __('Delivery: :method', ['method' => str_replace('_', ' ', $subOrder->shipping_method ?? 'standard')]) }} · {{ strtoupper($subOrder->order->payment_method) }}</p>
    </section>
    <table class="w-full text-sm">
        <thead class="border-b border-line text-start">
        <tr><th class="py-2 text-start">{{ __('SKU') }}</th><th class="py-2 text-start">{{ __('Item') }}</th><th class="py-2 text-end">{{ __('Qty') }}</th><th class="py-2 text-end">{{ __('Packed') }}</th></tr>
        </thead>
        <tbody>
        @foreach ($subOrder->items as $item)
            <tr class="border-b border-line">
                <td class="py-2">{{ $item->sku }}</td>
                <td class="py-2">{{ $item->title }}</td>
                <td class="py-2 text-end">{{ $item->quantity }}</td>
                <td class="py-2 text-end">☐</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="text-xs text-fg-secondary">{{ __('Total units: :count', ['count' => $subOrder->items->sum('quantity')]) }}</p>
</main>
</body>
</html>
