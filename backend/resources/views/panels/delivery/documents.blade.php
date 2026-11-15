<x-layouts.panel :title="__('Documents')" :subtitle="__('Driving licence, ID and vehicle papers checked by Nawy')" :breadcrumbs="[__('Account'), __('Documents')]">
    <x-ui.card :title="__('Upload')">
        @if ($requirements->isEmpty())
            <p class="text-sm text-fg-secondary">{{ __('No documents are asked in this country.') }}</p>
        @else
            <form method="POST" action="{{ route('delivery.documents.store') }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-3">
                @csrf
                <x-ui.select :label="__('Document')" name="document_type_id" :options="$requirements->mapWithKeys(fn ($type) => [$type->id => $type->name().($type->requirement->value === 'mandatory' ? ' *' : '')])->all()" />
                <div class="flex flex-col gap-1.5"><label for="file" class="text-sm font-medium">{{ __('File') }}</label><input id="file" type="file" name="file" required class="text-sm"></div>
                <x-ui.input :label="__('Valid until')" name="valid_until" type="date" />
                <div><x-ui.button type="submit">{{ __('Upload') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>
    <x-ui.card :title="__('Uploaded')" flush>
        <x-ui.table :headers="[__('Document'), __('File'), __('Valid until'), __('Status')]" :rows="$uploads->isNotEmpty()" :empty="__('Nothing uploaded yet.')">
            @foreach ($uploads as $upload)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $upload->type()?->name() ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $upload->original_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $upload->valid_until?->format('j M Y') ?? '—' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$upload->status" />@if ($upload->rejection_reason)<p class="text-xs text-fg-secondary">{{ $upload->rejection_reason }}</p>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
