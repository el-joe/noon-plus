<x-layouts.panel :title="__('COD settlements')" :subtitle="__('Cash remitted by delivery agents in :country · generated daily 23:30 · settling unlocks vendor payouts', ['country' => $country->name_en])" :breadcrumbs="[__('Finance'), __('COD settlements')]">
    <nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Finance sections') }}">
        @can('payouts.view')<a href="{{ route('admin.finance.payouts') }}" class="px-2 pb-2 text-fg-secondary">{{ __('Payout lines') }}</a>@endcan
        <a href="{{ route('admin.finance.cod-settlements') }}" class="border-b-2 border-brand px-2 pb-2 font-medium">{{ __('COD settlement') }}</a>
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Settlement'), __('Agent'), __('Day'), __('Collected'), __('Remitted'), __('Status'), '']" :rows="$settlements->isNotEmpty()" :empty="__('No COD settlements yet.')">
            @foreach ($settlements as $settlement)
                <tr class="border-t border-line align-top" data-cod-settlement="{{ $settlement->id }}">
                    <td class="px-5 py-3 font-medium">{{ $settlement->number }}<span class="block text-xs text-fg-secondary">{{ trans_choice(':count delivery|:count deliveries', $settlement->collections_count) }}</span></td>
                    <td class="px-5 py-3">{{ $settlement->agent_user_id ? __('Agent #:id', ['id' => $settlement->agent_user_id]) : __('Unassigned') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $settlement->business_date->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$settlement->expected_amount" :currency="$settlement->currency" /></td>
                    <td class="px-5 py-3">@if ($settlement->remitted_amount !== null)<x-ui.money :amount="$settlement->remitted_amount" :currency="$settlement->currency" />@else — @endif</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$settlement->status" /></td>
                    <td class="px-5 py-3">
                        @can('cod_settlements.settle')
                            @if ($settlement->status !== \App\Domains\Finance\Enums\CodSettlementStatus::Settled)
                                <form method="POST" action="{{ route('admin.finance.cod-settlements.settle', $settlement->id) }}" class="flex items-end gap-2">
                                    @csrf
                                    <x-ui.input :label="__('Cash received')" name="remitted_amount" :id="'remitted-'.$settlement->id" :value="(string) app(\App\Shared\Money\MoneyFactory::class)->of((string) $settlement->expected_amount, $settlement->currency)->getAmount()" inputmode="decimal" />
                                    <x-ui.button type="submit" size="sm">{{ __('Mark settled') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $settlements->links() }}
</x-layouts.panel>
