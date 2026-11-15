<x-layouts.panel :title="__('Marketer contract')" :breadcrumbs="[__('Creator Studio'), __('Contract')]">
    @if ($version === null)
        <x-ui.card><p class="text-sm text-fg-secondary">{{ __('No marketer contract is published in your country. You can take campaigns once your account is active.') }}</p></x-ui.card>
    @else
        <x-ui.card :title="$version->template->name.' · v'.$version->version">
            @if ($version->isFile())
                <p class="text-sm">{{ __('This contract is a document provided by Nawy.') }}</p>
            @else
                <article class="whitespace-pre-line text-sm" data-contract-text>{{ $text }}</article>
            @endif
        </x-ui.card>
        @if ($accepted)
            <x-ui.badge tone="success">{{ __('Accepted') }}</x-ui.badge>
        @else
            <x-ui.card :title="__('Accept')">
                <form method="POST" action="{{ route('marketer.contract.sign') }}" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.input :label="__('Full name')" name="full_name" required />
                    <x-ui.checkbox :label="__('I have read and accept this contract')" name="accept" />
                    <div><x-ui.button type="submit">{{ __('Accept contract') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    @endif
</x-layouts.panel>
