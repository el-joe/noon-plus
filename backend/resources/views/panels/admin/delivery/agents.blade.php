<x-layouts.panel :title="__('Delivery agents')" :subtitle="__(':country · create agents, verify documents, assign zones', ['country' => $country->name_en])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Agents')]">
    @include('panels.admin.delivery._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Agent'), __('Company'), __('Vehicle'), __('Zones'), __('Assignments'), __('Status'), '']" :rows="$agents->isNotEmpty()" :empty="__('No delivery agents yet.')">
            @foreach ($agents as $agent)
                <tr class="border-t border-line align-top" data-agent="{{ $agent->id }}">
                    <td class="px-5 py-3 font-medium">{{ $agent->name }}<span class="block text-xs text-fg-secondary">{{ $agent->phone }}</span></td>
                    <td class="px-5 py-3">{{ $agent->carrier?->name ?? __('Nawy fleet') }}</td>
                    <td class="px-5 py-3 text-xs">{{ $agent->vehicle_type }} {{ $agent->vehicle_plate }}</td>
                    <td class="px-5 py-3 text-xs">{{ empty($agent->zone_ids) ? __('All zones') : collect($agent->zone_ids)->map(fn ($id) => $zones[$id] ?? '#'.$id)->implode(', ') }}</td>
                    <td class="px-5 py-3">{{ $agent->assignments_count }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$agent->status" /></td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($agent->status->allowedNext() as $next)
                                <form method="POST" action="{{ route('admin.delivery.agents.status', $agent->id) }}">
                                    @csrf
                                    <input type="hidden" name="to" value="{{ $next->value }}">
                                    <x-ui.button type="submit" size="sm" :variant="$next === \App\Domains\Delivery\Enums\AgentStatus::Suspended ? 'danger' : 'outline'">
                                        {{ match ($next) { \App\Domains\Delivery\Enums\AgentStatus::Active => $agent->status === \App\Domains\Delivery\Enums\AgentStatus::PendingDocuments ? __('Verify & activate') : __('Activate'), \App\Domains\Delivery\Enums\AgentStatus::Unavailable => __('Mark unavailable'), \App\Domains\Delivery\Enums\AgentStatus::Suspended => __('Suspend'), default => $next->value } }}
                                    </x-ui.button>
                                </form>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $agents->links() }}

    @if ($invitations->isNotEmpty())
        <x-ui.card :title="__('Invited drivers')" :subtitle="__('Waiting for the driver to accept the emailed invitation')" flush>
            <x-ui.table :headers="[__('Name'), __('Email'), __('Expires (Oman)'), '']">
                @foreach ($invitations as $invitation)
                    <tr class="border-t border-line" data-agent-invitation="{{ $invitation->id }}">
                        <td class="px-5 py-3">{{ $invitation->name }}</td>
                        <td class="px-5 py-3">{{ $invitation->email }}</td>
                        <td class="px-5 py-3 text-xs">{{ $invitation->expires_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                        <td class="px-5 py-3 text-end"><form method="POST" action="{{ route('admin.delivery.agents.invitations.revoke', $invitation->id) }}">@csrf<x-ui.button type="submit" variant="ghost" size="sm">{{ __('Revoke') }}</x-ui.button></form></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Invite a driver')" :subtitle="__('The driver gets an email link to accept and set a password; the agent is created on acceptance')">
        <form method="POST" action="{{ route('admin.delivery.agents.store') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf
            <x-ui.input :label="__('Email')" name="email" type="email" required />
            <x-ui.input :label="__('Full name')" name="name" required />
            <x-ui.input :label="__('Phone')" name="phone" />
            <x-ui.select :label="__('Company')" name="carrier_id" :options="$carriers->all()" :placeholder="__('Nawy fleet')" />
            <x-ui.select :label="__('Vehicle')" name="vehicle_type" :options="['bike' => __('Bike'), 'car' => __('Car'), 'van' => __('Van'), 'truck' => __('Truck')]" :placeholder="__('—')" />
            <x-ui.input :label="__('Plate')" name="vehicle_plate" />
            <div class="flex flex-col gap-1.5 sm:col-span-2">
                <label for="zone_ids" class="text-sm font-medium">{{ __('Zones (none = all zones)') }}</label>
                <select id="zone_ids" name="zone_ids[]" multiple class="min-h-20 rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                    @foreach ($zones as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="flex items-end"><x-ui.button type="submit">{{ __('Send invitation') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</x-layouts.panel>
