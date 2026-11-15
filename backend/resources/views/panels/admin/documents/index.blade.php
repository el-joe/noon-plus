<x-layouts.panel :title="__('Document review')" :subtitle="__('Store documents and product certifications waiting for a decision')" :breadcrumbs="[__('Operations'), __('Document review')]">
    <x-slot:actions>
        @can('document_types.configure')<x-ui.button variant="outline" :href="route('admin.documents.types')">{{ __('Document types') }}</x-ui.button>@endcan
    </x-slot:actions>
    <form method="GET" class="flex gap-2">
        <x-ui.select name="status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()" :selected="$status" onchange="this.form.submit()" />
    </form>
    <x-ui.card flush>
        <x-ui.table :headers="[__('Owner'), __('Document'), __('Category'), __('Uploaded'), __('File'), '']" :rows="$documents->isNotEmpty()" :empty="__('Nothing to review.')">
            @foreach ($documents as $document)
                <tr class="border-t border-line" data-document="{{ $document->id }}">
                    <td class="px-5 py-3 text-xs">{{ $document->owner_type }} #{{ $document->owner_id }}</td>
                    <td class="px-5 py-3">{{ $types->get($document->document_type_id)?->name_en ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $document->category?->name_en ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $document->created_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') }}</td>
                    <td class="px-5 py-3"><a class="text-sm text-fg-link" href="{{ route('admin.documents.download', $document->id) }}">{{ $document->original_name }}</a></td>
                    <td class="px-5 py-3">
                        @if ($document->status->value === 'pending')
                            @can('documents.review')
                                <form method="POST" action="{{ route('admin.documents.review', $document->id) }}" class="flex gap-2">@csrf
                                    <input name="reason" class="h-8 rounded border border-line px-2 text-xs" placeholder="{{ __('Reason if rejecting') }}">
                                    <x-ui.button type="submit" size="sm" name="decision" value="approve">{{ __('Approve') }}</x-ui.button>
                                    <x-ui.button type="submit" size="sm" variant="outline" name="decision" value="reject">{{ __('Reject') }}</x-ui.button>
                                </form>
                            @endcan
                        @else
                            <x-ui.status-badge :status="$document->status" />
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $documents->links() }}
</x-layouts.panel>
