@php($sectionEnum = \App\Domains\Vendors\Enums\ChangeRequestSection::class)
<x-layouts.panel :error-summary="false" :title="__('Change requests')" :subtitle="__('Locked profile details change only after Nawy reviews them')" :breadcrumbs="[__('Account'), __('Change requests')]">
    @if ($errors->hasAny(collect($errors->keys())->filter(fn ($key) => str_starts_with($key, 'values.') || $key === 'note')->all()))
        <ul role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger" data-change-errors>
            @foreach (collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'values.') || $key === 'note') as $messages)
                <li>{{ $messages[0] }}</li>
            @endforeach
        </ul>
    @endif
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($sections as $key => $entry)
            <x-ui.card :title="$entry['section']->label()" data-section="{{ $key }}">
                <dl class="mb-4 grid gap-2 text-sm sm:grid-cols-2">
                    @foreach ($entry['current'] as $field => $value)
                        <div><dt class="text-xs text-fg-secondary">{{ $sectionEnum::fieldLabel($field) }}</dt>
                            <dd>{{ $field === 'iban' && $value ? '•••• '.substr((string) $value, -4) : ($value ?? '—') }}</dd></div>
                    @endforeach
                </dl>
                <details>
                    <summary class="cursor-pointer text-sm font-medium text-fg-link">{{ __('Ask for a change') }}</summary>
                    <form method="POST" action="{{ route($panel.'.change-requests.store') }}" class="mt-3 flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="section" value="{{ $key }}">
                        @foreach (array_keys($entry['section']->fields()) as $field)
                            <x-ui.input :label="$sectionEnum::fieldLabel($field)" name="values[{{ $field }}]" :id="$key.'-'.$field" :value="$field === 'iban' ? null : $entry['current'][$field]" />
                        @endforeach
                        <x-ui.textarea :label="__('Note for Nawy')" name="note" :id="$key.'-note'" :rows="2" />
                        <div><x-ui.button type="submit">{{ __('Send request') }}</x-ui.button></div>
                    </form>
                </details>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.card :title="__('Your change requests')" flush>
        <x-ui.table :headers="[__('Request'), __('Section'), __('Sent'), __('Status'), '']" :rows="$requests->isNotEmpty()" :empty="__('No change requests yet.')">
            @foreach ($requests as $row)
                <tr class="border-t border-line" data-change-request="{{ $row->number }}">
                    <td class="px-5 py-3 font-medium">{{ $row->number }}</td>
                    <td class="px-5 py-3">{{ $row->section->label() }}@if ($row->rejection_reason)<span class="block text-xs text-fg-danger">{{ $row->rejection_reason }}</span>@endif</td>
                    <td class="px-5 py-3 text-xs">{{ $row->created_at?->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$row->status" /></td>
                    <td class="px-5 py-3 text-end">
                        @if ($row->status === \App\Domains\Vendors\Enums\ChangeRequestStatus::Pending)
                            <form method="POST" action="{{ route($panel.'.change-requests.cancel', $row->id) }}">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $requests->links() }}
</x-layouts.panel>
