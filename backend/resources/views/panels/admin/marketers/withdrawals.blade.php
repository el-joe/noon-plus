<x-layouts.panel :title="__('Marketer withdrawals')" :breadcrumbs="[__('Growth'), __('Marketers'), __('Withdrawals')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Number'), __('Marketer'), __('Amount'), __('Status'), '']" :rows="$withdrawals->isNotEmpty()">
            @foreach ($withdrawals as $withdrawal)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $withdrawal->number }}</td>
                    <td class="px-5 py-3">{{ $withdrawal->marketer->display_name }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$withdrawal->amount" :currency="$withdrawal->currency" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$withdrawal->status" /></td>
                    <td class="px-5 py-3">
                        @can('marketer_withdrawals.process')
                            @unless (in_array($withdrawal->status->value, ['processed', 'rejected']))
                                <form method="POST" action="{{ route('admin.marketers.withdrawals.update', $withdrawal->id) }}" class="flex gap-2">@csrf
                                    <x-ui.button type="submit" size="sm" name="action" :value="$withdrawal->status->value === 'pending' ? 'approve' : 'process'">{{ $withdrawal->status->value === 'pending' ? __('Approve') : __('Mark paid') }}</x-ui.button>
                                    <x-ui.button type="submit" size="sm" variant="outline" name="action" value="reject">{{ __('Reject') }}</x-ui.button>
                                </form>
                            @endunless
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $withdrawals->links() }}
</x-layouts.panel>
