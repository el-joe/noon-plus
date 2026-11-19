@php($canManage = app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'travel_agency', 'inquiries', true))
<x-layouts.panel :title="__('Inquiries')" :subtitle="__('Questions from travellers about your trips')" :breadcrumbs="[__('Bookings'), __('Inquiries')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('From'), __('Trip'), __('Message'), __('Status'), __('Action')]" :rows="$inquiries->isNotEmpty()" :empty="__('No inquiries yet.')">
            @foreach ($inquiries as $inquiry)
                <tr class="border-t border-line align-top">
                    <td class="px-5 py-3">{{ $inquiry->name }}<span class="block text-xs text-fg-secondary">{{ $inquiry->email ?? $inquiry->phone }}</span></td>
                    <td class="px-5 py-3">{{ $inquiry->package->title_en }}</td>
                    <td class="px-5 py-3 text-sm">{{ $inquiry->message }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$inquiry->status" /></td>
                    <td class="px-5 py-3">
                        @foreach ($canManage ? $inquiry->status->allowedNext() : [] as $next)
                            <form method="POST" action="{{ route('travel_agency.inquiries.move', $inquiry->id) }}" class="inline">@csrf<input type="hidden" name="status" value="{{ $next->value }}"><x-ui.button type="submit" size="sm" variant="outline">{{ ucfirst($next->value) }}</x-ui.button></form>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $inquiries->links() }}
</x-layouts.panel>
