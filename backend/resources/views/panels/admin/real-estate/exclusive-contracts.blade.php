<x-layouts.panel :title="__('Exclusive contracts')" :subtitle="__('Marketer exclusivity on a property ad or a whole category in :country · window in local time', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Verticals'), __('Real estate')]">

    @can('marketers.manage')
        <x-ui.card :title="__('Grant exclusivity')">
            <form method="POST" action="{{ route('admin.real-estate.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2">
                @csrf
                <x-ui.select :label="__('Marketer')" name="marketer_profile_id" :selected="old('marketer_profile_id')" :options="$marketers->all()" required />
                <x-ui.select :label="__('Covers')" name="subject_type" :selected="old('subject_type', 'ad')" :options="['ad' => __('One ad'), 'category' => __('A whole category')]" />
                <x-ui.input :label="__('Ad number (for one ad)')" name="ad_number" :value="old('ad_number')" />
                <x-ui.select :label="__('Category (for a whole category)')" name="category_id" :selected="old('category_id')" :placeholder="__('Choose')" :options="$categories->all()" />
                <x-ui.datetime-input :label="__('Starts')" name="starts_at" :country="$country" required />
                <x-ui.datetime-input :label="__('Ends')" name="ends_at" :country="$country" required />
                <x-ui.input :label="__('Signed contract (PDF or image, private)')" name="file" type="file" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Grant') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card flush>
        <x-ui.table :headers="[__('Marketer'), __('Covers'), __('Window (Oman time)'), __('Status'), __('Actions')]" :rows="$contracts->isNotEmpty()" :empty="__('No exclusive contracts yet.')">
            @foreach ($contracts as $contract)
                <tr class="border-t border-line align-top" data-exclusive-contract="{{ $contract->id }}">
                    <td class="px-5 py-3 text-sm">{{ $contract->marketer?->display_name }}</td>
                    <td class="px-5 py-3 text-sm">{{ $contract->subject_type === 'ad' ? __('Ad #:id', ['id' => $contract->subject_id]) : __('Category #:id', ['id' => $contract->subject_id]) }}</td>
                    <td class="px-5 py-3 text-xs">{{ $clock->inAdminTime($contract->starts_at)->format('j M Y H:i') }} → {{ $clock->inAdminTime($contract->ends_at)->format('j M Y H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$contract->status" /></td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            @if ($contract->file_path)
                                <a href="{{ route('admin.real-estate.file', $contract->id) }}" class="text-sm underline">{{ __('Contract file') }}</a>
                            @endif
                            @can('marketers.manage')
                                @if (in_array($contract->status->value, ['scheduled', 'active'], true))
                                    <form method="POST" action="{{ route('admin.real-estate.revoke', $contract->id) }}" class="flex gap-2">
                                        @csrf
                                        <label class="sr-only" for="revoke-{{ $contract->id }}">{{ __('Reason') }}</label>
                                        <input id="revoke-{{ $contract->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Revoke') }}</x-ui.button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $contracts->links() }}
</x-layouts.panel>
