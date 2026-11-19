{{-- Account → Support in the partner panels (QC-007). --}}
@php($label = fn ($value) => \App\Shared\Localization\Label::of($value))
<x-layouts.panel :title="__('Support')" :subtitle="__('Your tickets with Nawy support')" :breadcrumbs="[__('Account'), __('Support')]">
    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card flush class="xl:col-span-2">
            <x-ui.table :headers="[__('Ticket'), __('Subject'), __('Category'), __('Last update'), __('Status')]" :rows="$tickets->isNotEmpty()" :empty="__('No tickets yet.')">
                @foreach ($tickets as $ticket)
                    <tr class="border-t border-line" data-ticket="{{ $ticket->id }}">
                        <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route($panel->route('support.show'), $ticket->id) }}">{{ $ticket->number }}</a></td>
                        <td class="px-5 py-3">{{ $ticket->subject }}</td>
                        <td class="px-5 py-3">{{ $label($ticket->category) }}</td>
                        <td class="px-5 py-3 text-xs">{{ ($ticket->last_message_at ?? $ticket->created_at)->setTimezone($country->timezone)->format('j M Y, H:i') }}</td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$ticket->status" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="__('New ticket')">
            <form method="POST" action="{{ route($panel->route('support.store')) }}" enctype="multipart/form-data" class="flex flex-col gap-3">
                @csrf
                <x-ui.select :label="__('Category')" name="category" :options="collect($categories)->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" required />
                <x-ui.select :label="__('Priority')" name="priority" selected="normal"
                             :options="collect(\App\Domains\Support\Enums\TicketPriority::requesterValues())->mapWithKeys(fn ($value) => [$value => $label($value)])->all()" />
                <x-ui.input :label="__('Subject')" name="subject" required maxlength="160" />
                @if ($panel->key === 'partner' || $panel->key === 'travel_agency')
                    <x-ui.input :label="__('Shipment number (optional)')" name="shipment" maxlength="40" />
                @endif
                <x-ui.textarea :label="__('How can we help?')" name="message" rows="5" required />
                <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" class="text-sm">
                <div><x-ui.button type="submit">{{ __('Send ticket') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    {{ $tickets->links() }}
</x-layouts.panel>
