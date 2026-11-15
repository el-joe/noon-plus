<x-layouts.panel :title="__('Shipping companies')" :subtitle="__(':country · carriers, their agents and contacts', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Shipping companies')]">
    @include('panels.admin.delivery._nav')

    @forelse ($carriers as $carrier)
        <x-ui.card :title="$carrier->name" :subtitle="$carrier->code.' · '.trans_choice(':count agent|:count agents', $carrier->agents_count)">
            <form method="POST" action="{{ route('admin.delivery.carriers.update', $carrier->id) }}" class="grid gap-4 sm:grid-cols-3" data-carrier="{{ $carrier->id }}">
                @csrf @method('PUT')
                <x-ui.input :label="__('Name')" name="name" :id="'name-'.$carrier->id" :value="$carrier->name" required />
                <x-ui.input :label="__('Contact')" name="contact_name" :id="'contact-'.$carrier->id" :value="$carrier->contact_name" />
                <x-ui.input :label="__('Email')" name="contact_email" :id="'email-'.$carrier->id" :value="$carrier->contact_email" />
                <x-ui.input :label="__('Phone')" name="contact_phone" :id="'phone-'.$carrier->id" :value="$carrier->contact_phone" />
                <x-ui.select :label="__('Status')" name="status" :id="'status-'.$carrier->id" :options="['active' => __('Active'), 'suspended' => __('Suspended')]" :selected="$carrier->status->value" />
                <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Nawy fleet')" name="is_internal" :id="'internal-'.$carrier->id" :checked="$carrier->is_internal" /><x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @empty
        <x-ui.card><p class="text-sm text-fg-secondary">{{ __('No shipping companies yet.') }}</p></x-ui.card>
    @endforelse

    <x-ui.card :title="__('New shipping company')">
        <form method="POST" action="{{ route('admin.delivery.carriers.store') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf
            <x-ui.input :label="__('Code')" name="code" required />
            <x-ui.input :label="__('Name')" name="name" required />
            <x-ui.input :label="__('Contact')" name="contact_name" />
            <x-ui.input :label="__('Email')" name="contact_email" />
            <x-ui.input :label="__('Phone')" name="contact_phone" />
            <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Nawy fleet')" name="is_internal" /><x-ui.button type="submit">{{ __('Add') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
