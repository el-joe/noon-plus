<x-layouts.panel :title="__('Marketers')" :subtitle="__('Approve, suspend and reactivate influencers, affiliates and brokers')" :breadcrumbs="[__('Growth'), __('Marketers')]">
    <x-slot:actions>
        @can('marketer_campaigns.view')<x-ui.button variant="outline" :href="route('admin.marketers.campaigns')">{{ __('Campaigns') }}</x-ui.button>@endcan
        @can('marketer_settings.configure')<x-ui.button variant="outline" :href="route('admin.marketers.settings')">{{ __('Settings') }}</x-ui.button>@endcan
        <x-ui.button variant="outline" :href="route('admin.marketers.withdrawals')">{{ __('Withdrawals') }}</x-ui.button>
        @can('cross_promotion.manage')<x-ui.button variant="outline" :href="route('admin.marketers.cross-promotion')">{{ __('Cross-promotion') }}</x-ui.button>@endcan
    </x-slot:actions>

    <form method="GET" class="flex flex-wrap gap-2">
        <x-ui.select name="status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" :selected="request('status')" :placeholder="__('All statuses')" />
        <input name="q" value="{{ request('q') }}" placeholder="{{ __('Search name') }}" class="h-10 rounded-lg border border-line px-3 text-sm">
        <x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Marketer'), __('Job types'), __('Followers'), __('Status'), '']" :rows="$marketers->isNotEmpty()">
            @foreach ($marketers as $marketer)
                <tr class="border-t border-line" data-marketer="{{ $marketer->id }}">
                    <td class="px-5 py-3">{{ $marketer->display_name }}<div class="text-xs text-fg-secondary">{{ $marketer->slug }}</div></td>
                    <td class="px-5 py-3 text-xs">{{ implode(', ', $marketer->job_types) }}</td>
                    <td class="px-5 py-3">{{ number_format($marketer->followers) }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$marketer->global_status" /></td>
                    <td class="px-5 py-3">
                        @can('marketers.manage')
                            <form method="POST" action="{{ route('admin.marketers.status', $marketer->id) }}" class="flex gap-2">
                                @csrf
                                <select name="status" class="h-8 rounded border border-line text-xs">
                                    @foreach ($marketer->global_status->allowedNext() as $next)<option value="{{ $next->value }}">{{ ucfirst($next->value) }}</option>@endforeach
                                </select>
                                <input name="reason" placeholder="{{ __('Reason') }}" class="h-8 rounded border border-line px-2 text-xs">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Apply') }}</x-ui.button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $marketers->links() }}
</x-layouts.panel>
