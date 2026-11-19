<x-layouts.panel :error-summary="false" :title="__('Domains')" :subtitle="__('Custom storefront domains, panel aliases and portal hosts. Storefront hosts open their market; panel aliases open the panel on their country.')" :breadcrumbs="[__('Countries'), __('Domains')]">
    @php($canManage = auth()->user()->can('domains.manage'))

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-line-danger bg-danger-subtle px-4 py-3 text-sm text-fg-danger">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <x-ui.card :title="__('Mapped hosts')" flush>
        <x-slot:actions>
            <form method="GET" class="flex items-end gap-2">
                <x-ui.select name="kind" :selected="$filters['kind'] ?? ''" :options="['' => __('All kinds')] + collect(\App\Domains\Countries\Enums\DomainKind::cases())->mapWithKeys(fn ($kind) => [$kind->value => __(\App\Shared\Localization\Label::key($kind))])->all()" :aria-label="__('Serves')" />
                <x-ui.button type="submit" variant="outline" size="md">{{ __('Filter') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <x-ui.table :headers="[__('Hostname'), __('Serves'), __('Country'), __('Target'), __('DNS'), __('TLS'), __('Status'), '']" :rows="$domains->isNotEmpty()" :empty="__('No custom domains yet — the storefront and panels use the built-in hosts.')">
            @foreach ($domains as $domain)
                @php($entry = $map->resolve($domain->hostname))
                <tr class="border-t border-line" data-domain="{{ $domain->hostname }}">
                    <td class="px-5 py-3 font-medium">{{ $domain->hostname }}@if ($domain->is_primary) <x-ui.badge tone="warning">{{ __('Primary') }}</x-ui.badge>@endif</td>
                    <td class="px-5 py-3">{{ __(\App\Shared\Localization\Label::key($domain->kind)) }}</td>
                    <td class="px-5 py-3">{{ $domain->country?->name_en ?? __('Any country') }}</td>
                    <td class="px-5 py-3 text-xs">
                        @if ($domain->redirect_to)
                            {{ __('301 → :host', ['host' => $domain->redirect_to]) }}
                        @elseif ($domain->kind->value === 'storefront')
                            {{ $entry['market'] ?? '—' }}
                        @elseif ($domain->kind->value === 'panel')
                            {{ $panels[$domain->panel] ?? $domain->panel }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs">{{ $domain->dns_verified_at ? __('Verified') : __('Not verified') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$domain->tls_status" /></td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$domain->is_active ? 'active' : 'inactive'" /></td>
                    <td class="px-5 py-3 text-end">@if ($canManage)<x-ui.button variant="ghost" size="sm" :href="route('admin.countries.domains.edit', $domain->id)">{{ __('Edit') }}</x-ui.button>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        @if ($canManage)
            <x-ui.card :title="__('Add a domain')">
                <form method="POST" action="{{ route('admin.countries.domains.store') }}" class="grid gap-3 sm:grid-cols-3">
                    @csrf
                    @include('panels.admin.countries._domain-fields', ['domain' => null])
                    <div><x-ui.button type="submit">{{ __('Add domain') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif

        <x-ui.card :title="__('DNS and TLS')">
            <ol class="list-decimal space-y-2 ps-5 text-sm text-fg-secondary">
                <li>{{ __('Storefront hosts: CNAME (or ALIAS at the apex) to :target.', ['target' => $storefrontTarget ?? __('the website edge')]) }}</li>
                <li>{{ __('Panel, portal and API hosts: CNAME to :target.', ['target' => $backendTarget ?? __('the backend load balancer')]) }}</li>
                <li>{{ __('Issue the TLS certificate for the host, then mark DNS verified and the certificate issued here.') }}</li>
                <li>{{ __('Changes apply within a few minutes (the website caches each host for 5 minutes).') }}</li>
            </ol>
        </x-ui.card>
    </div>
</x-layouts.panel>
