<x-layouts.panel :title="__('Profile change requests')" :subtitle="__('Locked store and agency details waiting for review')" :breadcrumbs="[__('Commerce'), __('Profile change requests')]">
    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach (\App\Domains\Vendors\Enums\ChangeRequestStatus::cases() as $case)
            <x-ui.button size="sm" :variant="$status === $case->value ? 'primary' : 'outline'" :href="route('admin.change-requests', ['status' => $case->value])">{{ __(\App\Shared\Localization\Label::key($case)) }}</x-ui.button>
        @endforeach
    </div>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Request'), __('Store or agency'), __('Section'), __('Sent (Oman)'), __('Status')]" :rows="$requests->isNotEmpty()" :empty="__('No requests here.')">
            @foreach ($requests as $row)
                <tr class="border-t border-line" data-change-request="{{ $row->number }}">
                    <td class="px-5 py-3"><a class="text-fg-link" href="{{ route('admin.change-requests.show', $row->id) }}">{{ $row->number }}</a></td>
                    <td class="px-5 py-3">{{ $row->vendor?->store_name }}<span class="block text-xs text-fg-secondary">{{ $row->requester_type === 'travel_agency' ? __('Travel agency') : __('Store') }}</span></td>
                    <td class="px-5 py-3">{{ $row->section->label() }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$row->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $requests->links() }}
</x-layouts.panel>
