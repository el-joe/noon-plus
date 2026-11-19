<x-layouts.panel :title="__('Email preferences')" :subtitle="__('Choose which emails you receive. Transactional and security emails are always sent.')" :breadcrumbs="[__('Account')]">
    <form method="POST" action="{{ route($panel->route('email-preferences.save')) }}" class="flex flex-col gap-6">
        @csrf
        <x-ui.card :title="__('Email categories')">
            <ul class="divide-y divide-line">
                @foreach ($categories as $category)
                    <li class="flex items-start justify-between gap-4 py-3">
                        <div>
                            <p class="text-sm font-medium">{{ $category['label'] }}</p>
                            <p class="text-xs text-fg-secondary">{{ $category['description'] }}</p>
                        </div>
                        @if ($category['locked'])
                            <x-ui.badge>{{ __('Always on') }}</x-ui.badge>
                        @else
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="topics[]" value="{{ $category['topic'] }}" @checked($category['enabled']) class="size-4 accent-brand"> {{ __('Receive') }}</label>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
        @if ($panel->key === 'admin')
            <x-ui.card :title="__('Operations alerts')" :subtitle="__('Opt in to email alerts for the queues you work on. Only alerts your permissions allow are listed.')">
                <ul class="divide-y divide-line">
                    @forelse ($alerts as $alert)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <div>
                                <p class="text-sm font-medium">{{ __($alert['label']) }}</p>
                                <p class="text-xs text-fg-secondary"><code>{{ $alert['permission'] }}</code></p>
                            </div>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="topics[]" value="{{ $alert['topic'] }}" @checked($alert['enabled']) class="size-4 accent-brand"> {{ __('Email me') }}</label>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-fg-secondary">{{ __('Your role has no alert permissions.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        @endif
        <div><x-ui.button type="submit">{{ __('Save preferences') }}</x-ui.button></div>
    </form>
</x-layouts.panel>
