<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Gift card sections') }}">
    <a href="{{ route('admin.gift-cards') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => ! request()->routeIs('admin.gift-cards.vouchers*'), 'text-fg-secondary' => request()->routeIs('admin.gift-cards.vouchers*')])>{{ __('Gift cards') }}</a>
    <a href="{{ route('admin.gift-cards.vouchers') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => request()->routeIs('admin.gift-cards.vouchers*'), 'text-fg-secondary' => ! request()->routeIs('admin.gift-cards.vouchers*')])>{{ __('Vouchers') }}</a>
</nav>
