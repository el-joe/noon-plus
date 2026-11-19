{{-- One ticket in the partner panels (QC-007): thread without internal notes. --}}
@php($label = fn ($value) => \App\Shared\Localization\Label::of($value))
@php($S = \App\Domains\Support\Enums\TicketStatus::class)
<x-layouts.panel :title="__('Ticket :number', ['number' => $ticket->number])" :subtitle="$ticket->subject" :breadcrumbs="[__('Account'), __('Support'), $ticket->number]">
    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Conversation')" class="xl:col-span-2">
            <ol class="flex flex-col gap-3">
                @foreach ($ticket->messages->reject->is_internal as $message)
                    <li class="rounded-lg border border-line p-3" data-ticket-message="{{ $message->id }}">
                        <p class="text-xs text-fg-secondary">{{ $message->isFromSupport() ? __('Nawy support') : __('You') }} · {{ $message->created_at->setTimezone($country->timezone)->format('j M Y, H:i') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $message->body }}</p>
                        @foreach (app(\App\Domains\Support\Support\TicketAttachments::class)->present($message) as $file)
                            <a class="mt-1 block text-xs text-fg-link" href="{{ $file['url'] }}" target="_blank" rel="noopener">{{ $file['name'] }}</a>
                        @endforeach
                    </li>
                @endforeach
            </ol>
            @if (in_array($ticket->status->value, \App\Domains\Support\Enums\TicketStatus::activeValues(), true))
                <form method="POST" action="{{ route($panel->route('support.reply'), $ticket->id) }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-2">
                    @csrf
                    <x-ui.textarea :label="__('Reply')" name="body" rows="3" required />
                    <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="text-sm">
                    <div><x-ui.button type="submit">{{ __('Send') }}</x-ui.button></div>
                </form>
            @endif
        </x-ui.card>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Details')">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$ticket->status" /></dd>
                    <dt class="text-fg-secondary">{{ __('Category') }}</dt><dd>{{ $label($ticket->category) }}</dd>
                    <dt class="text-fg-secondary">{{ __('Priority') }}</dt><dd>{{ $label($ticket->priority) }}</dd>
                    <dt class="text-fg-secondary">{{ __('Shipment') }}</dt><dd>{{ $ticket->subOrder?->number ?? '—' }}</dd>
                    @if ($ticket->first_responded_at === null && $ticket->first_response_due_at)
                        <dt class="text-fg-secondary">{{ __('Reply expected by') }}</dt><dd>{{ $ticket->first_response_due_at->setTimezone($country->timezone)->format('j M Y, H:i') }}</dd>
                    @endif
                </dl>
            </x-ui.card>

            @if ($canReopen)
                <x-ui.card :title="__('Not solved?')">
                    <form method="POST" action="{{ route($panel->route('support.reopen'), $ticket->id) }}" class="flex flex-col gap-2">
                        @csrf
                        <x-ui.textarea :label="__('What is still wrong? (optional)')" name="message" rows="2" />
                        <div><x-ui.button type="submit" variant="outline">{{ __('Reopen ticket') }}</x-ui.button></div>
                    </form>
                </x-ui.card>
            @endif

            @if (in_array($ticket->status, [$S::Resolved, $S::Closed], true))
                <x-ui.card :title="__('Rate our support')">
                    @if ($ticket->rated_at)
                        <p class="text-sm">{{ __('You rated this ticket :rating / 5.', ['rating' => $ticket->rating]) }}</p>
                    @else
                        <form method="POST" action="{{ route($panel->route('support.rate'), $ticket->id) }}" class="flex flex-col gap-2">
                            @csrf
                            <x-ui.select :label="__('Rating')" name="rating" :options="[5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1']" />
                            <x-ui.textarea :label="__('Comment (optional)')" name="comment" rows="2" />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Send rating') }}</x-ui.button></div>
                        </form>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</x-layouts.panel>
