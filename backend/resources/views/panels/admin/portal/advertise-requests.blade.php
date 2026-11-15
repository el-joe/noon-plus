@php($clock = app(\App\Domains\Countries\Support\CountryClock::class))
<x-layouts.panel :title="__('Advertise requests')" :subtitle="__('From the portal form /advertise/request · times in Oman time')" :breadcrumbs="[__('Content'), __('Advertise requests')]">
    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :placeholder="__('Open (new and contacted)')" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" :selected="$filters['status'] ?? null" onchange="this.form.submit()" />
    </form>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Request'), __('Contact'), __('Campaign'), __('Received (Oman)'), __('Status'), '']" :rows="$inquiries->isNotEmpty()" :empty="__('No advertise requests.')">
            @foreach ($inquiries as $inquiry)
                <tr class="border-t border-line align-top" data-inquiry="{{ $inquiry->number }}">
                    <td class="px-5 py-3 font-medium">{{ $inquiry->number }}<p class="text-xs text-fg-secondary">{{ $inquiry->company ?? '—' }}</p></td>
                    <td class="px-5 py-3 text-sm">{{ $inquiry->name }}<p class="text-xs text-fg-secondary">{{ $inquiry->email }} · {{ $inquiry->phone }}</p></td>
                    <td class="px-5 py-3 text-xs">{{ str_replace('_', ' ', ucfirst($inquiry->advertiser_type)) }} · {{ str_replace('_', ' ', $inquiry->interest) }} · {{ \App\Domains\Onboarding\Models\AdvertiseInquiry::budgetLabel($inquiry->monthly_budget, app(\App\Domains\Countries\Support\CountryContext::class)->current()->currency) ?? '—' }}
                        @if ($inquiry->message)<p class="mt-1 max-w-xs text-fg-secondary">{{ $inquiry->message }}</p>@endif
                        @if ($inquiry->admin_note)<p class="mt-1 max-w-xs">{{ __('Note') }}: {{ $inquiry->admin_note }}</p>@endif
                    </td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($inquiry->created_at)->format('j M Y H:i') }}
                        @foreach ($history->get($inquiry->id, collect())->skip(1) as $row)
                            <p class="text-fg-secondary">→ {{ $row->to_status }} · {{ $clock->inAdminTime($row->created_at)->format('j M H:i') }}</p>
                        @endforeach
                    </td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$inquiry->status" /></td>
                    <td class="px-5 py-3">
                        @can('advertise_inquiries.manage')
                            @if ($inquiry->status->allowedNext() !== [])
                                <form method="POST" action="{{ route('admin.advertise-requests.move', $inquiry->id) }}" class="flex flex-col gap-2">@csrf
                                    <input name="note" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Note (optional)') }}">
                                    <div class="flex gap-2">
                                        @foreach ($inquiry->status->allowedNext() as $next)
                                            <x-ui.button type="submit" size="sm" :variant="$next->value === 'closed' ? 'outline' : 'primary'" name="status" :value="$next->value">{{ ucfirst($next->value) }}</x-ui.button>
                                        @endforeach
                                    </div>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $inquiries->links() }}
</x-layouts.panel>
