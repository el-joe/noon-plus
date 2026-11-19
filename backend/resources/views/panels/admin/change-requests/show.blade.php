@php($sectionEnum = \App\Domains\Vendors\Enums\ChangeRequestSection::class)
<x-layouts.panel :title="$changeRequest->number" :subtitle="($changeRequest->vendor?->store_name ?? '').' · '.$changeRequest->section->label()" :breadcrumbs="[__('Commerce'), __('Profile change requests'), $changeRequest->number]">
    <x-slot:actions><x-ui.status-badge :status="$changeRequest->status" /></x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Requested change')" flush class="xl:col-span-2">
            <x-ui.table :headers="[__('Field'), __('Current'), __('Requested')]">
                @foreach ($changeRequest->requested_values as $field => $value)
                    @php($current = $changeRequest->current_values[$field] ?? null)
                    <tr class="border-t border-line" data-field="{{ $field }}">
                        <td class="px-5 py-3 text-fg-secondary">{{ $sectionEnum::fieldLabel($field) }}</td>
                        <td class="px-5 py-3">{{ $current ?? '—' }}</td>
                        <td class="px-5 py-3 {{ $current !== $value ? 'font-semibold' : '' }}">{{ $value ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($changeRequest->note)
                <p class="border-t border-line px-5 py-3 text-sm"><span class="text-fg-secondary">{{ __('Note for Nawy') }}:</span> {{ $changeRequest->note }}</p>
            @endif
            @if ($changeRequest->rejection_reason)
                <p class="border-t border-line px-5 py-3 text-sm text-fg-danger">{{ $changeRequest->rejection_reason }}</p>
            @endif
        </x-ui.card>

        @can('change_requests.review')
            @if ($changeRequest->status === \App\Domains\Vendors\Enums\ChangeRequestStatus::Pending)
                <div class="flex flex-col gap-6">
                    <x-ui.card :title="__('Approve and apply')">
                        <form method="POST" action="{{ route('admin.change-requests.approve', $changeRequest->id) }}" class="flex flex-col gap-3">
                            @csrf
                            @if ($changeRequest->section === $sectionEnum::BankDetails)
                                <x-ui.checkbox :label="__('I verified the new bank account (statement or bank letter in the name of the business)')" name="bank_verified" />
                            @endif
                            <div><x-ui.button type="submit">{{ __('Approve') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                    <x-ui.card :title="__('Reject')">
                        <form method="POST" action="{{ route('admin.change-requests.reject', $changeRequest->id) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.textarea :label="__('Reason')" name="reason" :rows="2" required />
                            <div><x-ui.button type="submit" variant="outline">{{ __('Reject') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                </div>
            @endif
        @endcan
    </div>

    @include('panels.admin._partials.transitions', ['transitions' => $changeRequest->transitions])
</x-layouts.panel>
