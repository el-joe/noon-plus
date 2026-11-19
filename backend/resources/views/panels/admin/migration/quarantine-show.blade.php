@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
@php($statusLabels = ['open' => __('Open'), 'resolved' => __('Marked resolved'), 'auto_resolved' => __('Migrated by a later run'), 'waived' => __('Waived')])
@php($statusTones = ['open' => 'warning', 'resolved' => 'info', 'auto_resolved' => 'success', 'waived' => 'neutral'])
@php($status = $entry->status())
@php($preview = $jobs->first(fn ($job) => $job->dry_run && $job->canApply()))
@php($format = fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? 'NULL')))
<x-layouts.panel :title="__('Quarantined :table row', ['table' => $entry->source_table])" :subtitle="__('Legacy id :id · step :step · last run :run', ['id' => $entry->source_id, 'step' => $entry->step, 'run' => \Illuminate\Support\Str::limit($entry->last_run_id, 8, '')])" :breadcrumbs="[__('Data migration'), __('Quarantine queue'), $entry->source_table]">
    <x-slot:actions>
        <x-ui.button variant="outline" size="sm" :href="route('admin.migration.quarantine')">{{ __('Back to the queue') }}</x-ui.button>
    </x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Why it is quarantined')">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge tone="danger" data-reason>{{ $entry->reason }}</x-ui.badge>
                    <x-ui.badge :tone="$statusTones[$status]" data-status="{{ $status }}">{{ $statusLabels[$status] }}</x-ui.badge>
                    @if ($entry->country_code)<x-ui.badge tone="neutral">{{ strtoupper($entry->country_code) }}</x-ui.badge>@endif
                </div>
                <p class="mt-3 text-sm">{{ \App\Domains\Migration\Support\Reconciler::mask((string) $entry->detail) ?: '—' }}</p>
                @if ($rule)
                    <p class="mt-2 text-xs text-fg-secondary">{{ __('Table rule: :rule · :disposition · :note', ['rule' => $rule['rule'], 'disposition' => $rule['disposition'], 'note' => $rule['note'] ?? '']) }}</p>
                @endif
                @if (in_array($entry->reason, ['parent_quarantined', 'parent_unmigrated'], true))
                    <p class="mt-2 text-xs text-fg-warning">{{ __('This row depends on its parent: fix or re-try the parent first, then re-try this row (or its whole step for the country).') }}</p>
                @endif
                <p class="mt-2 text-xs text-fg-secondary">{{ __('First quarantined :first · last seen :last (Oman)', ['first' => $oman($entry->created_at), 'last' => $oman($entry->updated_at)]) }}@if ($entry->resolved_at) · {{ __('migrated :at', ['at' => $oman($entry->resolved_at)]) }}@endif</p>
            </x-ui.card>

            <x-ui.card :title="__('Legacy row')" :subtitle="$revealed ? __('Personal data shown — this view was logged. Passwords and tokens are never shown.') : __('Personal data is masked.')">
                <x-slot:actions>
                    @can('data_migration.manage')
                        @unless ($revealed)
                            <form method="POST" action="{{ route('admin.migration.quarantine.reveal', $entry) }}" data-reveal>@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Reveal personal data') }}</x-ui.button></form>
                        @endunless
                    @endcan
                </x-slot:actions>
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[minmax(10rem,auto)_1fr]" data-payload @if ($revealed) data-revealed @endif>
                    @forelse ($payload as $key => $value)
                        <dt class="font-mono text-xs text-fg-secondary">{{ $key }}</dt>
                        <dd class="break-all font-mono text-xs">{{ $format($value) }}</dd>
                    @empty
                        <dd class="text-fg-secondary">{{ __('No row stored.') }}</dd>
                    @endforelse
                </dl>
            </x-ui.card>

            <x-ui.card :title="__('Related ids')" :subtitle="__('Where the ids of this row went, and which of them are quarantined too.')" flush>
                <x-ui.table :headers="[__('Column'), __('Legacy id'), __('Migrated to'), __('Quarantined')]" :rows="count($related) > 0 || $targets->isNotEmpty()" :empty="__('No related ids.')">
                    @if ($targets->isNotEmpty())
                        <tr class="border-t border-line bg-subtle">
                            <td class="px-5 py-2 font-mono text-xs">{{ __('this row') }}</td>
                            <td class="px-5 py-2 font-mono text-xs">{{ $entry->source_id }}</td>
                            <td class="px-5 py-2 font-mono text-xs">{{ $targets->map(fn ($t) => $t->target_db.'.'.$t->target_table.' #'.$t->target_id)->implode(', ') }}</td>
                            <td class="px-5 py-2">—</td>
                        </tr>
                    @endif
                    @foreach ($related as $item)
                        <tr class="border-t border-line align-top" data-related="{{ $item['column'] }}">
                            <td class="px-5 py-2 font-mono text-xs">{{ $item['column'] }}</td>
                            <td class="px-5 py-2 font-mono text-xs">{{ $item['id'] }}</td>
                            <td class="px-5 py-2 font-mono text-xs">{{ $item['targets']->map(fn ($t) => $t->source_table.' → '.$t->target_db.'.'.$t->target_table.' #'.$t->target_id)->implode(', ') ?: __('not migrated') }}</td>
                            <td class="px-5 py-2 text-xs">
                                @forelse ($item['quarantined'] as $other)
                                    <a class="block text-fg-link" href="{{ route('admin.migration.quarantine.show', $other->id) }}">{{ $other->source_table }} · {{ $other->reason }}{{ $other->resolved_at ? ' ('.__('migrated').')' : '' }}</a>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="flex flex-col gap-6">
            <x-ui.card :title="__('Decision')">
                @if ($entry->review_status)
                    <p class="text-sm" data-review>{{ $statusLabels[$entry->review_status] ?? $entry->review_status }} · {{ $reviewer?->name ?? __('Unknown') }} · {{ $oman($entry->reviewed_at) }}</p>
                    @if ($entry->review_note)<p class="mt-1 whitespace-pre-line text-sm text-fg-secondary">{{ $entry->review_note }}</p>@endif
                @else
                    <p class="text-sm text-fg-secondary">{{ __('No decision yet.') }}</p>
                @endif

                @can('data_migration.manage')
                    @if ($entry->resolved_at === null)
                        <form method="POST" action="{{ route('admin.migration.quarantine.review', $entry) }}" class="mt-4 flex flex-col gap-3" data-review-form>
                            @csrf
                            <x-ui.select :label="__('Decision')" name="decision" :options="array_filter(['resolved' => __('Mark resolved (fixed at the source)'), 'waived' => __('Ignore / waive (stays unmigrated)'), 'reopen' => $entry->review_status ? __('Reopen') : null])" />
                            <x-ui.textarea :label="__('Note (required)')" name="note" rows="3" />
                            <div><x-ui.button type="submit">{{ __('Save decision') }}</x-ui.button></div>
                        </form>
                    @endif
                @endcan
            </x-ui.card>

            <x-ui.card :title="__('Re-try')" :subtitle="__('Runs the :step step again for this one row. Dry run first: nothing is written until you apply it.', ['step' => $entry->step])">
                @can('data_migration.manage')
                    @if ($entry->resolved_at === null)
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.migration.quarantine.preview', $entry) }}" data-preview>@csrf<x-ui.button type="submit" variant="outline">{{ __('Preview re-try (dry run)') }}</x-ui.button></form>
                            @if ($preview)
                                <form method="POST" action="{{ route('admin.migration.jobs.apply', $preview->id) }}" data-apply>@csrf<x-ui.button type="submit">{{ __('Apply re-try') }}</x-ui.button></form>
                            @endif
                        </div>
                        @if ($entry->country_code)
                            <form method="POST" action="{{ route('admin.migration.retries.store') }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="step" value="{{ $entry->step }}">
                                <input type="hidden" name="country" value="{{ $entry->country_code }}">
                                <x-ui.button type="submit" variant="ghost" size="sm">{{ __('Preview the whole :step step for :country', ['step' => $entry->step, 'country' => strtoupper($entry->country_code)]) }}</x-ui.button>
                            </form>
                        @endif
                    @else
                        <p class="text-sm text-fg-success">{{ __('Migrated: nothing to re-try.') }}</p>
                    @endif
                @endcan

                @if ($jobs->isNotEmpty())
                    <ul class="mt-4 flex flex-col gap-3 text-sm">
                        @foreach ($jobs as $job)
                            <li class="rounded-lg border border-line p-3" data-job="{{ $job->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $job->id) }}">#{{ $job->id }}</a>
                                    @include('panels.admin.migration._job-status', ['job' => $job])
                                    <span class="text-xs text-fg-secondary">{{ $oman($job->created_at) }}</span>
                                </div>
                                @if ($job->status === 'done')
                                    <p class="mt-1 text-xs">
                                        {{ __('read :read · written :written · unchanged :unchanged · quarantined :quarantined', ['read' => $job->total('read'), 'written' => $job->total('written') + $job->total('archived') + $job->total('updated'), 'unchanged' => $job->total('unchanged'), 'quarantined' => $job->total('quarantined')]) }}
                                    </p>
                                    @if (($job->metrics['row']['read'] ?? 1) === 0)
                                        <p class="mt-1 text-xs text-fg-warning">{{ __('The step did not read this row on its own (it moves with its parent). Re-try the parent or the whole step for the country.') }}</p>
                                    @endif
                                @elseif ($job->status === 'failed')
                                    <p class="mt-1 text-xs text-fg-danger">{{ $job->output }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Audit trail')">
                <ol class="flex flex-col gap-1 text-xs" data-audit>
                    @forelse ($history as $item)
                        <li>{{ $oman($item->created_at) }} · {{ $item->description }}</li>
                    @empty
                        <li class="text-fg-secondary">{{ __('No review activity yet.') }}</li>
                    @endforelse
                </ol>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
