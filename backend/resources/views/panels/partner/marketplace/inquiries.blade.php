<x-layouts.panel :title="__('Inquiries')" :subtitle="__('Buyer messages on your ads')" :breadcrumbs="[__('Buyers'), __('Inquiries')]">
    @forelse ($threads as $thread)
        <x-ui.card :title="$thread->ad->title" :subtitle="$thread->contact_name.' · '.$thread->contact_phone.' · '.str_replace('_', ' ', $thread->status->value)">
            <ol class="grid gap-2 text-sm">
                @foreach ($thread->messages as $message)
                    <li @class(['rounded-lg px-3 py-2', 'bg-subtle' => $message->sender_role === 'buyer', 'bg-brand-subtle text-end' => $message->sender_role === 'seller'])>{{ $message->body }}</li>
                @endforeach
            </ol>
            @if ($thread->status->value !== 'closed')
                <form method="POST" action="{{ route('partner.marketplace.inquiries.reply', $thread->id) }}" class="mt-3 flex gap-2">
                    @csrf
                    <label class="sr-only" for="reply-{{ $thread->id }}">{{ __('Reply') }}</label>
                    <input id="reply-{{ $thread->id }}" name="body" required class="h-9 flex-1 rounded-lg border border-line bg-surface px-3 text-sm" placeholder="{{ __('Write a reply') }}">
                    <x-ui.button type="submit" size="sm">{{ __('Send') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @empty
        <p class="text-sm text-fg-secondary">{{ __('No inquiries yet.') }}</p>
    @endforelse
    {{ $threads->links() }}
</x-layouts.panel>
