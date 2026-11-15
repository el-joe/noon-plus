@php($store = $vendor->business_details['store'] ?? [])
@php($address = $vendor->business_details['address'] ?? [])
<x-layouts.panel :title="__('Store settings')" :subtitle="$vendor->store_name.' · '.$vendor->slug" :breadcrumbs="[__('Account'), __('Store settings')]">
    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Business details')" :subtitle="__('Locked — ask Nawy to change these')">
            <dl class="grid gap-2 text-sm sm:grid-cols-2" data-business-details>
                <div><dt class="text-xs text-fg-secondary">{{ __('Store name') }}</dt><dd>{{ $vendor->store_name }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Legal name') }}</dt><dd>{{ $store['legal_name'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Business type') }}</dt><dd>{{ str_replace('_', ' ', $store['business_type'] ?? '—') }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Trade licence') }}</dt><dd>{{ $store['cr_number'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Tax ID') }}</dt><dd>{{ $store['tax_id'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Fulfilment') }}</dt><dd>{{ strtoupper($store['fulfilment'] ?? '—') }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-fg-secondary">{{ __('Address') }}</dt><dd>{{ collect($address)->only(['building', 'street', 'area', 'city'])->filter()->implode(', ') ?: '—' }}</dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$vendor->status" /></dd></div>
                <div><dt class="text-xs text-fg-secondary">{{ __('Application') }}</dt><dd>{{ $vendor->business_details['application'] ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>
        <x-ui.card :title="__('Contact & description')">
            <form method="POST" action="{{ route('partner.store.update') }}" class="flex flex-col gap-3" data-store-form>
                @csrf
                @method('PUT')
                <x-ui.input :label="__('Contact phone')" name="contact_phone" :value="$vendor->contact_phone" required />
                <x-ui.textarea :label="__('Store description')" name="description" :value="$store['description'] ?? null" :rows="4" />
                <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.panel>
