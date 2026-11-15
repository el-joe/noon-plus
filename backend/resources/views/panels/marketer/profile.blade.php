@php($handles = collect($marketer->socials ?? [])->pluck('handle', 'network'))
<x-layouts.panel :title="__('Profile & socials')" :subtitle="__('Shown in the creator directory and on your boutique')" :breadcrumbs="[__('Main'), __('Profile & socials')]">
    <x-ui.card :title="$marketer->display_name" :subtitle="__('Status: :status', ['status' => $marketer->global_status->value]).' · '.implode(', ', $marketer->job_types)">
        <form method="POST" action="{{ route('marketer.profile.update') }}" class="grid gap-3 sm:grid-cols-2" data-profile-form>
            @csrf
            @method('PUT')
            <x-ui.input :label="__('Display name')" name="display_name" :value="$marketer->display_name" required />
            <x-ui.input :label="__('WhatsApp number (invitations)')" name="whatsapp_phone" :value="$marketer->whatsapp_phone" required />
            <div class="sm:col-span-2"><x-ui.textarea :label="__('Short bio (160)')" name="bio" :value="$marketer->bio" :rows="2" /></div>
            <x-ui.input :label="__('Followers')" name="followers" type="number" :value="$marketer->followers" />
            <div></div>
            @foreach ($networks as $network)
                <x-ui.input :label="ucfirst($network)" :name="'socials['.$network.']'" :id="'social-'.$network" :value="$handles->get($network)" />
            @endforeach
            <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
