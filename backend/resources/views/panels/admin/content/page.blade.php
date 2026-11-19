@php($P = \App\Domains\Content\Enums\PageStatus::class)
<x-layouts.panel :title="$page->title" :subtitle="'/'.$page->slug.' · '.strtoupper($page->locale).' · '.$page->template->label()" :breadcrumbs="[__('Content'), __('Pages')]">
    @include('panels.admin.content._nav')

    <div class="flex flex-wrap items-center gap-3">
        <x-ui.status-badge :status="$page->status" />
        <span class="text-xs text-fg-secondary">{{ __('Draft revision :draft · live revision :live', ['draft' => $page->currentRevision?->number ?? '—', 'live' => $page->publishedRevision?->number ?? '—']) }}</span>
        @if ($page->publish_at)<span class="text-xs">{{ __('Publishes :at (Oman)', ['at' => $clock->inAdminTime($page->publish_at)->format('j M Y, H:i')]) }}</span>@endif
    </div>

    @if ($problems)
        <div role="alert" class="rounded-lg border border-line bg-warning-subtle px-4 py-3 text-sm text-fg-warning">
            <p class="font-medium">{{ __('Not ready to publish:') }}</p>
            <ul class="list-disc ps-5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </div>
    @endif

    @can('pages.manage')
        <form method="POST" action="{{ route('admin.pages.save', $page->id) }}" class="flex flex-col gap-6" data-page-editor>
            @csrf
            <x-ui.card :title="__('Page')">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input :label="__('Title')" name="title" :value="$page->title" required />
                    <x-ui.input :label="__('SEO title')" name="seo_title" :value="$page->seo_title" />
                    <x-ui.input :label="__('SEO description')" name="seo_description" :value="$page->seo_description" />
                </div>
            </x-ui.card>

            @foreach ($zones as $zone => $blocks)
                <x-ui.card :title="__('Zone: :zone', ['zone' => $zone])" :subtitle="trans_choice(':count block|:count blocks', count($blocks))">
                    <div class="flex flex-col gap-3">
                        @foreach ($blocks as $index => $block)
                            @php($definition = \App\Domains\Content\Support\BlockRegistry::get($block['type']))
                            <div class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-12" data-block="{{ $block['type'] }}">
                                <input type="hidden" name="blocks[{{ $zone }}][{{ $index }}][id]" value="{{ $block['id'] }}">
                                <input type="hidden" name="blocks[{{ $zone }}][{{ $index }}][type]" value="{{ $block['type'] }}">
                                <div class="sm:col-span-3">
                                    <p class="font-medium">{{ $definition['label'] ?? $block['type'] }}</p>
                                    <p class="text-xs text-fg-secondary">{{ $definition['group'] ?? '' }} · {{ $definition['kind'] ?? '' }}</p>
                                    @if (! empty($definition['required']))<p class="text-xs text-fg-secondary">{{ __('Needs: :list', ['list' => implode(', ', $definition['required'])]) }}</p>@endif
                                </div>
                                <div class="sm:col-span-7">
                                    <x-ui.textarea :label="__('Settings (JSON)')" :name="'blocks['.$zone.']['.$index.'][settings]'" :id="'settings-'.$zone.'-'.$index" :value="json_encode($block['settings'] ?: new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)" rows="4" class="font-mono text-xs" />
                                </div>
                                <div class="flex flex-col gap-2 sm:col-span-2">
                                    <x-ui.input :label="__('Order')" :name="'blocks['.$zone.']['.$index.'][order]'" :id="'order-'.$zone.'-'.$index" type="number" :value="($index + 1) * 10" />
                                    <x-ui.checkbox :label="__('Remove')" :name="'blocks['.$zone.']['.$index.'][remove]'" :id="'remove-'.$zone.'-'.$index" />
                                </div>
                            </div>
                        @endforeach
                        <div class="flex items-end gap-2">
                            <x-ui.select :label="__('Add a block')" :name="'add['.$zone.']'" :id="'add-'.$zone" :placeholder="__('Choose…')">
                                @foreach ($blockGroups as $group => $types)
                                    <optgroup label="{{ ucfirst($group) }}">
                                        @foreach ($types as $type => $label)
                                            @if (\App\Domains\Content\Support\BlockRegistry::allowedIn($type, $zone))<option value="{{ $type }}">{{ $label }}</option>@endif
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach

            <div class="flex items-end gap-3">
                <x-ui.input :label="__('Revision note')" name="note" />
                <x-ui.button type="submit">{{ __('Save draft revision') }}</x-ui.button>
            </div>
        </form>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card :title="__('Publish')">
                <div class="flex flex-col gap-3">
                    @if ($page->status !== $P::Archived)
                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'publish']) }}">@csrf<x-ui.button type="submit" :disabled="$problems !== []">{{ $page->status === $P::Published ? __('Publish latest draft') : __('Publish now') }}</x-ui.button></form>
                    @endif
                    @if ($page->status === $P::Draft)
                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'schedule']) }}" class="flex flex-col gap-2">@csrf
                            <x-ui.datetime-input :label="__('Publish at')" name="publish_at" :country="$country" />
                            <x-ui.button type="submit" variant="outline" :disabled="$problems !== []">{{ __('Schedule') }}</x-ui.button></form>
                    @endif
                    @if (in_array($page->status, [$P::Scheduled, $P::Published, $P::Archived], true))
                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'draft']) }}">@csrf<x-ui.button type="submit" variant="outline">{{ $page->status === $P::Scheduled ? __('Unschedule') : ($page->status === $P::Published ? __('Unpublish') : __('Back to draft')) }}</x-ui.button></form>
                    @endif
                    @if (in_array($page->status, [$P::Draft, $P::Published], true))
                        <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'archive']) }}">@csrf<x-ui.button type="submit" variant="danger">{{ __('Archive') }}</x-ui.button></form>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Copy & reuse')">
                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'copy']) }}" class="mb-4 flex flex-col gap-2">@csrf
                    <span class="text-sm font-medium">{{ __('Copy the draft to (as drafts)') }}</span>
                    @foreach ($countries as $other)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="countries[]" value="{{ $other->code }}" class="size-4 accent-brand"> {{ $other->name_en }}</label>
                    @endforeach
                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Copy') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'layout']) }}" class="flex items-end gap-2">@csrf
                    <x-ui.input :label="__('Save as layout')" name="name" /><x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button></form>
            </x-ui.card>

            <x-ui.card :title="__('Revisions')" :subtitle="__('Append-only; restoring copies into a new revision')">
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($revisions as $revision)
                        <li class="flex items-center justify-between gap-2" data-revision="{{ $revision->number }}">
                            <span>#{{ $revision->number }} · {{ $clock->inAdminTime($revision->created_at)->format('j M H:i') }} <span class="text-xs text-fg-secondary">{{ $revision->note }}</span></span>
                            @if ($revision->id !== $page->current_revision_id)
                                <form method="POST" action="{{ route('admin.pages.act', [$page->id, 'restore']) }}">@csrf<input type="hidden" name="revision" value="{{ $revision->number }}"><x-ui.button type="submit" size="sm" variant="ghost">{{ __('Restore') }}</x-ui.button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    @endcan

    <x-ui.state-timeline :title="__('Status history')" :steps="$history->map(fn ($row) => ['state' => 'done', 'label' => ucfirst($row->to_status), 'meta' => ucfirst((string) $row->actor_type).($row->actor_user_id ? ' '.app(\App\Shared\Support\UserNames::class)->name($row->actor_user_id) : '').' · '.$clock->inAdminTime($row->created_at)->format('j M H:i').($row->reason ? ' · '.$row->reason : '')])->all()" />
</x-layouts.panel>
