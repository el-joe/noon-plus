<x-layouts.panel :title="__('Profile & vehicle')" :subtitle="$agent->name.' · '.($agent->carrier?->name ?? __('Nawy fleet'))" :breadcrumbs="[__('Account'), __('Profile & vehicle')]">
    <x-ui.card :title="__('Your details')" :subtitle="__('Status: :status', ['status' => str_replace('_', ' ', $agent->status->value)])">
        <form method="POST" action="{{ route('delivery.profile.update') }}" class="grid gap-3 sm:grid-cols-3" data-profile-form>
            @csrf
            @method('PUT')
            <x-ui.input :label="__('Phone')" name="phone" :value="$agent->phone" required />
            <x-ui.select :label="__('Vehicle')" name="vehicle_type" :options="['bike' => __('Bike'), 'car' => __('Car'), 'van' => __('Van'), 'truck' => __('Truck')]" :selected="$agent->vehicle_type" :placeholder="__('Choose…')" />
            <x-ui.input :label="__('Plate')" name="vehicle_plate" :value="$agent->vehicle_plate" />
            <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
        </form>
    </x-ui.card>
    <x-ui.card :title="__('Last location')">
        <p class="text-sm">{{ $agent->last_seen_at ? __('Shared :when from the app', ['when' => $agent->last_seen_at->diffForHumans()]) : __('The app shares your location while you are available.') }}</p>
    </x-ui.card>
</x-layouts.panel>
