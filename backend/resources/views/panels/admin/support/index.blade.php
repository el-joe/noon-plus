@php($label = fn ($value) => \App\Shared\Localization\Label::of($value))
<x-layouts.panel :title="__('Support tickets')" :subtitle="__('Tickets from customers and partners in :country', ['country' => $country->name_en])" :breadcrumbs="[__('Support'), __('Tickets')]">
    <section class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach (\App\Domains\Support\Enums\TicketStatus::cases() as $status)
            <a href="{{ route('admin.support', ['status' => $status->value]) }}" data-count="{{ $status->value }}">
                <x-ui.stat-card :label="$label($status)" :value="(int) ($counts[$status->value] ?? 0)" />
            </a>
        @endforeach
        <a href="{{ route('admin.support', ['overdue' => 1]) }}" data-count="overdue"><x-ui.stat-card :label="__('Overdue (SLA)')" :value="$overdueCount" /></a>
    </section>

    <form method="GET" action="{{ route('admin.support') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-3 xl:grid-cols-7" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Ticket number or subject')" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All statuses')"
                     :options="collect(\App\Domains\Support\Enums\TicketStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" />
        <x-ui.select :label="__('Priority')" name="priority" :selected="$filters['priority'] ?? ''" :placeholder="__('All priorities')"
                     :options="collect(\App\Domains\Support\Enums\TicketPriority::cases())->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" />
        <x-ui.select :label="__('Category')" name="category" :selected="$filters['category'] ?? ''" :placeholder="__('All categories')"
                     :options="collect(\App\Domains\Support\Enums\TicketCategory::cases())->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" />
        <x-ui.select :label="__('Requester')" name="requester" :selected="$filters['requester'] ?? ''" :placeholder="__('Everyone')"
                     :options="collect(\App\Domains\Support\Enums\TicketRequesterType::cases())->mapWithKeys(fn ($case) => [$case->value => $label($case)])->all()" />
        <x-ui.select :label="__('Assignee')" name="assignee" :selected="$filters['assignee'] ?? ''" :placeholder="__('Anyone')"
                     :options="['me' => __('Assigned to me'), 'unassigned' => __('Unassigned (:count open)', ['count' => $unassignedCount])] + $admins->mapWithKeys(fn ($admin) => [(string) $admin->id => $admin->name])->all()" />
        <div class="flex items-end gap-3">
            <x-ui.checkbox :label="__('Overdue only')" name="overdue" :checked="(bool) ($filters['overdue'] ?? false)" />
            <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
        </div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Ticket'), __('Subject'), __('Requester'), __('Category'), __('Priority'), __('Reply due'), __('Status')]" :rows="$tickets->isNotEmpty()" :empty="__('No tickets.')">
            @foreach ($tickets as $ticket)
                <tr class="border-t border-line" data-ticket="{{ $ticket->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.support.show', $ticket->id) }}">{{ $ticket->number }}</a></td>
                    <td class="px-5 py-3">{{ $ticket->subject }}</td>
                    <td class="px-5 py-3">{{ $requesters[$ticket->requester_user_id]->name ?? '—' }} <span class="text-xs text-fg-secondary">({{ $label($ticket->requester_type) }})</span></td>
                    <td class="px-5 py-3">{{ $label($ticket->category) }}</td>
                    <td class="px-5 py-3">{{ $label($ticket->priority) }}</td>
                    <td class="px-5 py-3 text-xs">
                        @if ($ticket->first_responded_at === null && $ticket->first_response_due_at)
                            {{ $ticket->first_response_due_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }} {{ __('Oman') }}
                        @else
                            —
                        @endif
                        @if ($ticket->isOverdue())
                            <x-ui.badge tone="danger">{{ __('Overdue') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$ticket->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $tickets->links() }}
</x-layouts.panel>
