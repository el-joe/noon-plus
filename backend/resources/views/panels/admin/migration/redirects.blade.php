@php($oman = fn ($moment) => $moment ? \Illuminate\Support\Carbon::parse($moment)->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') : '—')
@php($storefront = rtrim((string) config('nawy.payments.storefront_url'), '/'))
<x-layouts.panel :title="__('Legacy redirects')" :subtitle="__('301s from old-platform URLs to the new website, served by the website itself and by the panels')" :breadcrumbs="[__('Data migration'), __('Legacy redirects')]">
    <x-slot:actions>
        @foreach (['json' => __('Export JSON'), 'map' => __('Export nginx map')] as $format => $label)
            @if ($exports[$format])
                <x-ui.button variant="outline" size="sm" :href="route('admin.migration.redirects.export', $format)" data-export="{{ $format }}">{{ $label }}</x-ui.button>
            @endif
        @endforeach
        @can('data_migration.manage')
            <form method="POST" action="{{ route('admin.migration.redirects.rebuild') }}" data-rebuild>@csrf<x-ui.button type="submit" size="sm">{{ __('Rebuild map') }}</x-ui.button></form>
        @endcan
    </x-slot:actions>

    <section class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card :label="__('Redirects')" :value="(string) $total" />
        <x-ui.stat-card :label="__('Storefront')" :value="(string) ($bySource['storefront'] ?? 0)" />
        <x-ui.stat-card :label="__('Old seller portal')" :value="(string) ($bySource['portal'] ?? 0)" />
        <x-ui.stat-card :label="__('Last export (Oman)')" :value="$exports['json'] ? $oman(\Illuminate\Support\Carbon::createFromTimestamp($exports['json']['modified'])) : __('Not exported yet')" />
    </section>

    @if ($lastRebuild)
        <p class="text-sm text-fg-secondary">{{ __('Last rebuild') }} <a class="text-fg-link" href="{{ route('admin.migration.jobs.show', $lastRebuild->id) }}">#{{ $lastRebuild->id }}</a> · {{ $lastRebuild->status }} · {{ $oman($lastRebuild->created_at) }}</p>
    @endif

    <form method="GET" action="{{ route('admin.migration.redirects') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Path')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Old or new path')" />
        <x-ui.select :label="__('Source')" name="source" :selected="$filters['source'] ?? ''" :placeholder="__('All')" :options="['storefront' => __('Storefront'), 'portal' => __('Old seller portal')]" />
        <x-ui.select :label="__('Country')" name="country" :selected="$filters['country'] ?? ''" :placeholder="__('All')" :options="collect($countries)->mapWithKeys(fn ($code) => [$code => strtoupper($code)])->all()" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Search') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Old URL'), __('New URL'), __('Status'), __('Source'), __('Country')]" :rows="$redirects->isNotEmpty()" :empty="__('No redirect matches. The map is built by php artisan legacy:redirects (or Rebuild map).')">
            @foreach ($redirects as $redirect)
                <tr class="border-t border-line" data-redirect="{{ $redirect->from_path }}">
                    <td class="px-5 py-2 font-mono text-xs break-all">{{ $redirect->from_path }}</td>
                    <td class="px-5 py-2 font-mono text-xs break-all"><a class="text-fg-link" href="{{ $storefront.$redirect->to_path }}" target="_blank" rel="noopener">{{ $redirect->to_path }}</a></td>
                    <td class="px-5 py-2">{{ $redirect->status }}</td>
                    <td class="px-5 py-2">{{ $redirect->source === 'portal' ? __('Old seller portal') : __('Storefront') }}</td>
                    <td class="px-5 py-2 text-xs uppercase">{{ $redirect->country_code ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $redirects->links() }}
</x-layouts.panel>
