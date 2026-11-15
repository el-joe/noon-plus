{{-- Dispute thread shared by admin and Seller Center (QC-001, SRS §7.6). --}}
<x-ui.card :title="__('Conversation')" :subtitle="__('Reason: :reason', ['reason' => str_replace('_', ' ', $dispute->reason->value)])">
    <ol class="flex flex-col gap-3">
        @foreach ($dispute->messages as $message)
            <li class="rounded-lg border border-line p-3" data-dispute-message="{{ $message->id }}">
                <p class="text-xs text-fg-secondary">{{ ucfirst($message->author_type->value ?? (string) $message->author_type) }} · {{ $message->created_at->setTimezone($timezone)->format('j M Y, H:i') }}</p>
                <p class="mt-1 whitespace-pre-line text-sm">{{ $message->body }}</p>
            </li>
        @endforeach
    </ol>
    @if ($dispute->status !== \App\Domains\Disputes\Enums\DisputeStatus::Closed && ($canReply ?? false))
        <form method="POST" action="{{ $replyAction }}" class="mt-4 flex flex-col gap-2">
            @csrf
            <x-ui.textarea :label="__('Reply')" name="body" rows="3" required />
            <div><x-ui.button type="submit">{{ __('Send') }}</x-ui.button></div>
        </form>
    @endif
</x-ui.card>
