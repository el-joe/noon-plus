<x-layouts.panel :title="__('Assignments')" :breadcrumbs="[__('Dispatch'), __('Assignments')]">
    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :placeholder="__('All statuses')" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :selected="$filters['status'] ?? null" onchange="this.form.submit()" />
    </form>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Assignment'), __('Driver'), __('Attempt'), __('SLA due'), __('Status'), '']" :rows="$assignments->isNotEmpty()" :empty="__('No assignments yet.')">
            @foreach ($assignments as $assignment)
                <tr class="border-t border-line" data-assignment="{{ $assignment->id }}">
                    <td class="px-5 py-3 font-medium">{{ $assignment->number }}</td>
                    <td class="px-5 py-3">{{ $assignment->agent?->name }}</td>
                    <td class="px-5 py-3">{{ $assignment->attempt_no }}</td>
                    <td class="px-5 py-3 text-xs">{{ $assignment->sla_due_at?->setTimezone($currentCountry->timezone)->format('j M H:i') ?? '—' }}@if ($assignment->sla_breached_at) <x-ui.badge tone="danger">{{ __('Late') }}</x-ui.badge>@endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$assignment->status" /></td>
                    <td class="px-5 py-3">
                        @if (in_array($assignment->status->value, ['assigned', 'accepted'], true) && $agents->except($assignment->delivery_agent_id)->isNotEmpty())
                            <form method="POST" action="{{ route('carrier.assignments.reassign', $assignment->id) }}" class="flex items-end gap-2">
                                @csrf
                                <x-ui.select name="agent_id" :id="'reassign-'.$assignment->id" :options="$agents->except($assignment->delivery_agent_id)->all()" :placeholder="__('Driver…')" />
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reassign') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $assignments->links() }}
</x-layouts.panel>
