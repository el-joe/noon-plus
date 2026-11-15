<x-layouts.panel :title="__('Drivers')" :subtitle="__('Agents of :company (no cap). New drivers are activated by Nawy after the document check.', ['company' => $currentCarrier->name])" :breadcrumbs="[__('Operations'), __('Drivers')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Driver'), __('Phone'), __('Vehicle'), __('Delivered'), __('Status'), '']" :rows="$agents->isNotEmpty()" :empty="__('No drivers yet.')">
            @foreach ($agents as $agent)
                <tr class="border-t border-line" data-agent="{{ $agent->id }}">
                    <td class="px-5 py-3 font-medium">{{ $agent->name }}</td>
                    <td class="px-5 py-3 text-xs">{{ $agent->phone ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $agent->vehicle_type ?? '—' }} {{ $agent->vehicle_plate }}</td>
                    <td class="px-5 py-3">{{ $agent->delivered_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$agent->status" /></td>
                    <td class="px-5 py-3">
                        <form method="POST" action="{{ route('carrier.agents.status', $agent->id) }}">@csrf
                            @if ($agent->status->value === 'suspended')
                                <input type="hidden" name="status" value="active"><x-ui.button type="submit" size="sm" variant="outline">{{ __('Activate') }}</x-ui.button>
                            @elseif (in_array($agent->status->value, ['active', 'unavailable'], true))
                                <input type="hidden" name="status" value="suspended"><x-ui.button type="submit" size="sm" variant="outline">{{ __('Suspend') }}</x-ui.button>
                            @endif
                        </form>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Add a driver')" :subtitle="__('They get their sign-in by email.')">
            <form method="POST" action="{{ route('carrier.agents.store') }}" class="grid gap-3 sm:grid-cols-2">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Email')" name="email" type="email" required />
                <x-ui.input :label="__('Phone')" name="phone" />
                <x-ui.select :label="__('Vehicle')" name="vehicle_type" :options="['bike' => __('Bike'), 'car' => __('Car'), 'van' => __('Van'), 'truck' => __('Truck')]" :placeholder="__('Choose…')" />
                <x-ui.input :label="__('Plate')" name="vehicle_plate" />
                <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('Add driver') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Supervisors')" :subtitle="$isCarrierOwner ? __('Only you, the owner, can add supervisors.') : __('Only the company owner can add supervisors.')">
            <ul class="mb-3 flex flex-col gap-1 text-sm">
                @foreach ($supervisors as $supervisor)
                    <li data-supervisor="{{ $supervisor->id }}">{{ $supervisor->name }} · <span class="text-fg-secondary">{{ $supervisor->email }}</span>@if ($supervisor->id === $currentCarrier->owner_user_id) <x-ui.badge>{{ __('Owner') }}</x-ui.badge>@endif</li>
                @endforeach
            </ul>
            @if ($isCarrierOwner)
                <form method="POST" action="{{ route('carrier.supervisors.store') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <x-ui.input :label="__('Name')" name="name" id="supervisor-name" required />
                    <x-ui.input :label="__('Email')" name="email" id="supervisor-email" type="email" required />
                    <div class="sm:col-span-2"><x-ui.button type="submit" variant="outline">{{ __('Add supervisor') }}</x-ui.button></div>
                </form>
            @endif
        </x-ui.card>
    </div>
</x-layouts.panel>
