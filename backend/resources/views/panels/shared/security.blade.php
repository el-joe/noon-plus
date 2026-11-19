<x-layouts.panel :title="__('Sign-in history')" :breadcrumbs="[__('Account')]">
    <x-ui.card :subtitle="__('Your last 50 sign-ins and attempts. If you do not recognise one, change your password.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-start text-xs text-fg-secondary">
                    <tr><th class="px-3 py-2 text-start">{{ __('When (Oman)') }}</th><th class="px-3 py-2 text-start">{{ __('Where') }}</th><th class="px-3 py-2 text-start">{{ __('Result') }}</th><th class="px-3 py-2 text-start">{{ __('IP address') }}</th><th class="px-3 py-2 text-start">{{ __('Device') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($events as $event)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $event->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                            <td class="px-3 py-2">{{ $panelNames[$event->channel] ?? \App\Shared\Localization\Label::of((string) $event->channel) }}</td>
                            <td class="px-3 py-2"><x-ui.badge :tone="$event->outcome?->value === 'succeeded' ? 'success' : 'warning'">{{ \App\Shared\Localization\Label::of($event->outcome) }}</x-ui.badge></td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $event->ip_address }}</td>
                            <td class="px-3 py-2 text-xs text-fg-secondary">{{ \Illuminate\Support\Str::limit((string) $event->user_agent, 60) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-fg-secondary">{{ __('No sign-ins recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</x-layouts.panel>
