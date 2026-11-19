<nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Ledger sections') }}">
    <a href="{{ route('admin.finance.ledger') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $section === 'accounts', 'text-fg-secondary' => $section !== 'accounts'])>{{ __('Accounts & balances') }}</a>
    <a href="{{ route('admin.finance.ledger.entries') }}" @class(['px-2 pb-2', 'border-b-2 border-brand font-medium' => $section === 'entries', 'text-fg-secondary' => $section !== 'entries'])>{{ __('Journal entries') }}</a>
</nav>
