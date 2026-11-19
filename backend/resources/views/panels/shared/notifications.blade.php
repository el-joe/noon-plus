<x-layouts.panel :title="__('Notifications')" :breadcrumbs="[__('Account')]">
    <x-ui.card>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-2 text-sm">
                <a href="{{ request()->url() }}" @class(['rounded-full px-3 py-1', 'bg-inverse text-fg-inverse' => ! $unreadOnly, 'bg-subtle' => $unreadOnly])>{{ __('All') }}</a>
                <a href="{{ request()->url() }}?unread=1" @class(['rounded-full px-3 py-1', 'bg-inverse text-fg-inverse' => $unreadOnly, 'bg-subtle' => ! $unreadOnly])>{{ __('Unread') }}</a>
            </div>
            <form method="POST" action="{{ route($panelKey.'.notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Mark all as read') }}</x-ui.button>
            </form>
        </div>
        <ul class="flex flex-col divide-y divide-line">
            @forelse ($notifications as $notification)
                <li class="py-3">
                    <form method="POST" action="{{ route($panelKey.'.notifications.read', $notification->id) }}" class="flex items-start justify-between gap-3">
                        @csrf
                        <button type="submit" class="flex flex-col items-start text-start">
                            <span @class(['text-sm', 'font-semibold' => $notification->read_at === null])>{{ $notification->title }}</span>
                            @if ($notification->body)<span class="text-xs text-fg-secondary">{{ $notification->body }}</span>@endif
                        </button>
                        <span class="shrink-0 text-xs text-fg-secondary">{{ $notification->created_at?->diffForHumans() }}</span>
                    </form>
                </li>
            @empty
                <li class="py-6 text-center text-sm text-fg-secondary">{{ __('No notifications yet.') }}</li>
            @endforelse
        </ul>
        <div class="mt-4">{{ $notifications->links() }}</div>
    </x-ui.card>
</x-layouts.panel>
