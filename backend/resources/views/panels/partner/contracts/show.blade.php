<x-layouts.panel :title="$version->template->name" :subtitle="__('Version :v · :kind', ['v' => $version->version, 'kind' => $version->template->kind->label()])" :breadcrumbs="[__('Seller Center'), __('Contracts'), 'v'.$version->version]">
    <x-ui.card>
        @if ($version->isFile())
            <p class="text-sm">{{ __('This contract is a document. Ask Nawy for a copy if you cannot open it; your signature records your name and the date.') }}</p>
        @else
            <article class="prose max-w-none text-sm whitespace-pre-line" data-contract-text>{{ $text }}</article>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Sign')" :subtitle="__('Your typed name, the time, your IP address and browser are stored with a sealed copy of this text.')">
        <form method="POST" action="{{ route('partner.contracts.sign', $version->id) }}" class="flex flex-col gap-4">
            @csrf
            @if ($categoryId)<input type="hidden" name="category_id" value="{{ $categoryId }}">@endif
            <x-ui.input :label="__('Full name')" name="full_name" required />
            <x-ui.checkbox :label="__('I have read and accept this contract')" name="accept" />
            @error('accept')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
            <div><x-ui.button type="submit">{{ __('Sign contract') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
