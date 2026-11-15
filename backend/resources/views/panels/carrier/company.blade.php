<x-layouts.panel :title="__('Company settings')" :subtitle="$carrier->name.' · '.__('Licence :n', ['n' => $carrier->licence_number ?? '—'])" :breadcrumbs="[__('Account'), __('Company settings')]">
    <x-ui.card :title="__('Contact & coverage')" :subtitle="$isCarrierOwner ? null : __('Only the company owner can change these.')">
        <form method="POST" action="{{ route('carrier.company.update') }}" class="grid gap-3 sm:grid-cols-2" data-company-form>
            @csrf
            @method('PUT')
            <x-ui.input :label="__('Contact name')" name="contact_name" :value="$carrier->contact_name" :disabled="! $isCarrierOwner" />
            <x-ui.input :label="__('Contact email')" name="contact_email" type="email" :value="$carrier->contact_email" :disabled="! $isCarrierOwner" />
            <x-ui.input :label="__('Contact phone')" name="contact_phone" :value="$carrier->contact_phone" :disabled="! $isCarrierOwner" />
            <x-ui.input :label="__('Fleet size')" name="fleet_size" type="number" :value="$carrier->details['fleet_size'] ?? null" :disabled="! $isCarrierOwner" />
            <div class="sm:col-span-2"><x-ui.input :label="__('Coverage (cities, comma separated)')" name="coverage" :value="implode(', ', $carrier->details['coverage'] ?? [])" :disabled="! $isCarrierOwner" /></div>
            @if ($isCarrierOwner)<div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>@endif
        </form>
    </x-ui.card>
    <p class="text-xs text-fg-secondary">{{ __('Service zones and pricing are agreed with Nawy; ask your account manager to change them.') }}</p>
</x-layouts.panel>
