<x-layouts.panel :title="$template->name" :subtitle="$template->kind->label().' · '.($template->country_id === null ? __('Legacy / global') : $currentCountry->name_en).' · '.$template->mode" :breadcrumbs="[__('Operations'), __('Contracts'), $template->name]">
    <section class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            @can('contract_templates.manage')
                <x-ui.card :title="$draft ? __('Draft v:v', ['v' => $draft->version]) : __('New draft')" :subtitle="__('Variables: {store_name} {signer_name} {date} {country} {marketer_name}')">
                    <form method="POST" action="{{ route('admin.contracts.draft', $template->id) }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                        @csrf
                        @if ($template->mode === 'file')
                            <label class="text-sm">{{ __('Arabic file (required)') }} <input type="file" name="file_ar" class="block text-sm"></label>
                            <label class="text-sm">{{ __('English file (optional)') }} <input type="file" name="file_en" class="block text-sm"></label>
                        @else
                            <x-ui.textarea :label="__('Arabic text')" name="body_ar" rows="8" :value="$draft?->body_ar ?? $template->versions->first()?->body_ar" dir="rtl" />
                            <x-ui.textarea :label="__('English text')" name="body_en" rows="8" :value="$draft?->body_en ?? $template->versions->first()?->body_en" />
                        @endif
                        <x-ui.input :label="__('Change summary')" name="change_summary" :value="$draft?->change_summary" />
                        <div><x-ui.button type="submit" variant="outline">{{ __('Save draft') }}</x-ui.button></div>
                    </form>
                    @if ($draft)
                        <form method="POST" action="{{ route('admin.contracts.publish', $draft->id) }}" class="mt-4 flex flex-wrap items-end gap-3">
                            @csrf
                            @if ($template->consent_mode === 'notice')
                                <x-ui.datetime-input :label="__('Effective (notice)')" name="effective_at" :country="$currentCountry" />
                            @endif
                            <x-ui.button type="submit">{{ $template->consent_mode === 'notice' ? __('Publish / schedule') : __('Publish (click-wrap)') }}</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endcan

            <x-ui.card :title="__('Versions')" flush>
                <x-ui.table :headers="[__('Version'), __('Status'), __('Effective'), __('Published'), __('Signatures')]" :rows="$template->versions->isNotEmpty()">
                    @foreach ($template->versions as $version)
                        <tr class="border-t border-line" data-version="{{ $version->id }}">
                            <td class="px-5 py-3">v{{ $version->version }}<div class="text-xs text-fg-secondary">{{ $version->change_summary }}</div></td>
                            <td class="px-5 py-3"><x-ui.status-badge :status="$version->status" /></td>
                            <td class="px-5 py-3 text-xs">{{ $version->effective_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') ?? '—' }}</td>
                            <td class="px-5 py-3 text-xs">{{ $version->published_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') ?? '—' }}</td>
                            <td class="px-5 py-3 text-xs">{{ $signatureCounts->where('contract_version_id', $version->id)->map(fn ($r) => $r->status.': '.$r->total)->implode(', ') ?: '—' }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card :title="__('Assignments')" :subtitle="__('Category / product links follow the newest published version')">
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($template->assignments as $assignment)
                    <li class="flex items-center justify-between">
                        {{ $assignment->subject_type }} #{{ $assignment->subject_key }} {{ $assignment->subject_type === 'category' ? '· '.($categories->firstWhere('id', (int) $assignment->subject_key)?->name_en) : '' }}
                        @can('contract_templates.manage')
                            <form method="POST" action="{{ route('admin.contracts.unassign', $assignment->id) }}">@csrf @method('DELETE')<x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button></form>
                        @endcan
                    </li>
                @endforeach
            </ul>
            @can('contract_templates.manage')
                <form method="POST" action="{{ route('admin.contracts.assign', $template->id) }}" class="mt-4 flex flex-col gap-3">
                    @csrf
                    <x-ui.select :label="__('Subject')" name="subject_type" :options="['category' => __('Category'), 'product' => __('Product')]" />
                    <x-ui.input :label="__('Category or product id')" name="subject_key" type="number" min="1" />
                    <x-ui.button type="submit" variant="outline">{{ __('Assign') }}</x-ui.button>
                </form>
            @endcan
        </x-ui.card>
    </section>
</x-layouts.panel>
