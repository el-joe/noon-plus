<x-layouts.panel :title="__('Contracts')" :subtitle="__('Seller Agreement, category contracts and collaboration contracts for :store', ['store' => $vendor->store_name])" :breadcrumbs="[__('Seller Center'), __('Account'), __('Contracts')]">
    <x-ui.card :title="__('Seller Agreement')">
        @if ($agreement)
            <div class="flex flex-wrap items-center justify-between gap-3" data-agreement>
                <p class="text-sm">{{ $agreement->template->name }} · v{{ $agreement->version }}</p>
                @if ($agreementSigned)
                    <x-ui.badge tone="success">{{ __('Signed') }}</x-ui.badge>
                @else
                    <x-ui.button :href="route('partner.contracts.show', $agreement->id)">{{ __('Read and sign') }}</x-ui.button>
                @endif
            </div>
        @else
            <p class="text-sm text-fg-secondary">{{ $agreementSigned ? __('Accepted at registration.') : __('No Seller Agreement published in this country yet.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Category contracts')" :subtitle="__('Needed before your first listing in these categories')" flush>
        <x-ui.table :headers="[__('Category'), __('Contract'), __('Status')]" :rows="$categories->isNotEmpty()" :empty="__('No category needs an individual contract.')">
            @foreach ($categories as $row)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $row['category']->name_en }}</td>
                    <td class="px-5 py-3 text-xs">{{ $row['resolved'] ? $row['resolved']->version->template->name.' · v'.$row['resolved']->version->version : __('Not configured yet — contact Nawy') }}</td>
                    <td class="px-5 py-3">
                        @if ($row['signed'])
                            <x-ui.badge tone="success">{{ __('Signed') }}</x-ui.badge>
                        @elseif ($row['resolved'])
                            <x-ui.button size="sm" :href="route('partner.contracts.show', ['version' => $row['resolved']->version->id, 'category' => $row['category']->id])">{{ __('Sign') }}</x-ui.button>
                        @else
                            <x-ui.badge tone="warning">{{ __('Unavailable') }}</x-ui.badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Collaboration contracts')" :subtitle="__('Signed before you invite marketers')" flush>
        <x-ui.table :headers="[__('Contract'), __('Status')]" :rows="$collaboration->isNotEmpty()" :empty="__('No collaboration contract applies in this country.')">
            @foreach ($collaboration as $row)
                <tr class="border-t border-line">
                    <td class="px-5 py-3">{{ $row['version']->template->name }} · v{{ $row['version']->version }}</td>
                    <td class="px-5 py-3">
                        @if ($row['signed'])<x-ui.badge tone="success">{{ __('Signed') }}</x-ui.badge>@else<x-ui.button size="sm" :href="route('partner.contracts.show', $row['version']->id)">{{ __('Sign') }}</x-ui.button>@endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Signed contracts')" flush>
        <x-ui.table :headers="[__('Kind'), __('Version'), __('Signed by'), __('When'), __('Status'), '']" :rows="$signatures->isNotEmpty()">
            @foreach ($signatures as $signature)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 text-xs">{{ $signature->kind->label() }}</td>
                    <td class="px-5 py-3">v{{ $signature->version }}</td>
                    <td class="px-5 py-3">{{ $signature->full_name }} <span class="text-xs text-fg-secondary">({{ $signature->method->value }})</span></td>
                    <td class="px-5 py-3 text-xs">{{ $signature->signed_at->format('j M Y H:i') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$signature->status" /></td>
                    <td class="px-5 py-3"><a class="text-fg-link text-sm" href="{{ route('partner.contracts.download', $signature->id) }}">{{ __('Download') }}</a></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
