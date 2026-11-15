<x-layouts.panel :title="__('Abuse reports')" :subtitle="__('Reported ads — actioning archives the ad')" :breadcrumbs="[__('Verticals'), __('Abuse reports')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Ad'), __('Reason'), __('Details'), __('Status'), __('Decision')]" :rows="$reports->isNotEmpty()" :empty="__('No open reports.')">
            @foreach ($reports as $report)
                <tr class="border-t border-line align-top" data-report="{{ $report->id }}">
                    <td class="px-5 py-3">{{ $report->ad->title }}<span class="block text-xs text-fg-secondary">{{ $report->ad->number }}</span></td>
                    <td class="px-5 py-3">{{ str_replace('_', ' ', $report->reason) }}</td>
                    <td class="px-5 py-3 text-sm">{{ $report->details }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$report->status" /></td>
                    <td class="px-5 py-3 flex flex-wrap gap-2">
                        @foreach ($report->status->allowedNext() as $next)
                            <form method="POST" action="{{ route('admin.classifieds.reports.decide', $report->id) }}">@csrf<input type="hidden" name="decision" value="{{ $next->value }}"><x-ui.button type="submit" size="sm" :variant="$next->value === 'actioned' ? 'primary' : 'outline'">{{ ucfirst($next->value) }}</x-ui.button></form>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $reports->links() }}
</x-layouts.panel>
