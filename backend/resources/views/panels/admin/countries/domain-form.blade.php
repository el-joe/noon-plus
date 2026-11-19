<x-layouts.panel :error-summary="false" :title="$domain->hostname" :subtitle="__('Edit the domain mapping.')" :breadcrumbs="[__('Countries'), __('Domains'), $domain->hostname]">
    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <x-ui.card :title="__('Mapping')">
        <form method="POST" action="{{ route('admin.countries.domains.update', $domain->id) }}" class="grid gap-3 sm:grid-cols-3">
            @csrf
            @method('PUT')
            @include('panels.admin.countries._domain-fields', ['domain' => $domain])
            <div class="flex items-end gap-2">
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.countries.domains')">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Remove')">
        <form method="POST" action="{{ route('admin.countries.domains.destroy', $domain->id) }}" onsubmit="return confirm(@js(__('Remove this domain mapping?')))">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" variant="outline">{{ __('Remove domain') }}</x-ui.button>
        </form>
    </x-ui.card>
</x-layouts.panel>
