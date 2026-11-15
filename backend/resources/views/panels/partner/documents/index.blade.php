<x-layouts.panel :title="__('Documents')" :subtitle="__('Store documents and product certifications reviewed by Nawy')" :breadcrumbs="[__('Seller Center'), __('Account'), __('Documents')]">
    @if ($missing->isNotEmpty())
        <div role="status" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">{{ __('Still needed: :list', ['list' => $missing->map->name()->implode(', ')]) }}</div>
    @endif

    <x-ui.card :title="__('Upload a document')">
        <form method="POST" action="{{ route('partner.documents.store') }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.select :label="__('Document')" name="document_type_id" :options="$requirements->concat($certificationTypes)->mapWithKeys(fn ($t) => [$t->id => $t->name().' · '.str_replace('_', ' ', $t->requirement->value).($t->business_type->value === 'certification' ? ' · '.__('certification') : '')])->all()" :placeholder="__('Choose')" required />
            <x-ui.select :label="__('Category (certifications)')" name="category_id" :options="$categories->pluck('name_en', 'id')->all()" :placeholder="__('Not a certification')" />
            <div class="flex flex-col gap-1.5">
                <label for="file" class="text-sm font-medium">{{ __('File (PDF, JPG, PNG)') }}</label>
                <input id="file" name="file" type="file" required class="text-sm">
                @error('file')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
            </div>
            <x-ui.input :label="__('Valid until (optional)')" name="valid_until" type="date" />
            <div><x-ui.button type="submit">{{ __('Upload') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Your uploads')" flush>
        <x-ui.table :headers="[__('File'), __('Category'), __('Uploaded'), __('Status'), '']" :rows="$uploads->isNotEmpty()">
            @foreach ($uploads as $document)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $document->original_name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $document->category?->name_en ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $document->created_at->format('j M Y') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$document->status" />@if ($document->rejection_reason)<div class="text-xs text-fg-danger">{{ $document->rejection_reason }}</div>@endif</td>
                    <td class="px-5 py-3"><a class="text-sm text-fg-link" href="{{ route('partner.documents.download', $document->id) }}">{{ __('Download') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
