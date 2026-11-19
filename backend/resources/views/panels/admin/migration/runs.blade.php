@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
<x-layouts.panel :title="__('Migration runs')" :subtitle="__('Every step of every legacy:migrate run: rows read, written and quarantined, money per currency · times in Oman')" :breadcrumbs="[__('Data migration'), __('Migration runs')]">
    <section class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Step runs recorded')" :value="(string) $runs->total()" />
        <x-ui.stat-card :label="__('Open quarantine')" :value="(string) $openQuarantine" :trend="$openQuarantine > 0 ? 'down' : 'up'" />
        <x-ui.stat-card :label="__('Latest run')" :value="$oman($runs->first()?->finished_at)" />
    </section>

    @can('data_migration.manage')
        <x-ui.card :title="__('Re-try a step')" :subtitle="__('Runs the step again for one country (or all). A dry run comes first; you apply it from its result page. Quarantine entries the run migrates leave the queue on their own.')">
            <form method="POST" action="{{ route('admin.migration.retries.store') }}" class="grid gap-3 sm:grid-cols-4" data-retry-step>
                @csrf
                <x-ui.select :label="__('Step')" name="step" :options="collect($steps)->mapWithKeys(fn ($step) => [$step => $step])->all()" required />
                <x-ui.select :label="__('Country')" name="country" :placeholder="__('All countries')" :options="collect($countries)->mapWithKeys(fn ($code) => [$code => strtoupper($code)])->all()" />
                <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Preview (dry run)') }}</x-ui.button></div>
            </form>
            @if ($jobs->isNotEmpty())
                <ul class="mt-4 flex flex-col gap-1 text-sm">
                    @foreach ($jobs as $job)
                        <li><a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $job->id) }}">#{{ $job->id }} · {{ $job->step }} · {{ $job->country_code ? strtoupper($job->country_code) : __('All countries') }}</a> · {{ $job->dry_run ? __('Dry run') : __('Run') }} · {{ $job->status }} · {{ $oman($job->created_at) }}</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endcan

    <form method="GET" action="{{ route('admin.migration.runs') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.select :label="__('Step')" name="step" :selected="$filters['step'] ?? ''" :placeholder="__('All')" :options="collect($steps)->mapWithKeys(fn ($step) => [$step => $step])->all()" />
        <x-ui.select :label="__('Country')" name="country" :selected="$filters['country'] ?? ''" :placeholder="__('All')" :options="collect($countries)->mapWithKeys(fn ($code) => [$code => strtoupper($code)])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Step'), __('Run'), __('Countries'), __('Read'), __('Written'), __('Quarantined'), __('Σ per currency'), __('Started (Oman)'), __('Finished (Oman)')]" :rows="$runs->isNotEmpty()" :empty="__('No migration run yet. Runs appear here after php artisan legacy:migrate.')">
            @foreach ($runs as $run)
                <tr class="border-t border-line align-top" data-run="{{ $run->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.migration.runs.show', $run->id) }}">{{ $run->step }}</a>@if ($run->scope)<span class="block font-mono text-xs text-fg-secondary">{{ $run->scope }}</span>@endif</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ \Illuminate\Support\Str::limit($run->run_id, 8, '') }}</td>
                    <td class="px-5 py-3 text-xs uppercase">{{ $run->countries ?: __('All') }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $run->totals['read'] ?? 0 }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ ($run->totals['written'] ?? 0) + ($run->totals['archived'] ?? 0) }}</td>
                    <td @class(['px-5 py-3 tabular-nums', 'font-semibold text-fg-danger' => ($run->totals['quarantined'] ?? 0) > 0])>{{ $run->totals['quarantined'] ?? 0 }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ collect($run->sums)->map(fn ($sum, $currency) => $currency.' '.$sum)->implode(' · ') ?: '—' }}</td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $oman($run->started_at) }}</td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">{{ $oman($run->finished_at) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $runs->links() }}
</x-layouts.panel>
