<x-layouts.panel :title="__('Assignments')" :breadcrumbs="[__('Today'), __('Assignments')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Assignment'), __('Order'), __('Attempt'), __('Status')]" :rows="$assignments->isNotEmpty()" :empty="__('No assignments yet.')">
            @foreach ($assignments as $assignment)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('delivery.assignments.show', $assignment->number) }}">{{ $assignment->number }}</a></td>
                    <td class="px-5 py-3">{{ $assignment->subOrder->order->number }}</td>
                    <td class="px-5 py-3">{{ $assignment->attempt_no }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$assignment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $assignments->links() }}
</x-layouts.panel>
