<x-layouts.panel :title="__('My day')" :subtitle="__('Hello :name', ['name' => $agent->name])" :breadcrumbs="[__('Today')]">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Open assignments')" :value="$open->count()" />
        <x-ui.stat-card :label="__('Delivered today')" :value="$deliveredToday" />
        <div class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-5">
            <span class="text-sm text-fg-secondary">{{ __('COD cash in hand') }}</span>
            <span class="text-2xl font-bold"><x-ui.money :amount="$codInHand" :currency="$currentCountry->currency" /></span>
        </div>
    </div>

    <x-ui.card :title="__('Availability')" :subtitle="__('Status: :status', ['status' => str_replace('_', ' ', $agent->status->value)])">
        @if (in_array($agent->status, [\App\Domains\Delivery\Enums\AgentStatus::Active, \App\Domains\Delivery\Enums\AgentStatus::Unavailable], true))
            <form method="POST" action="{{ route('delivery.availability') }}">
                @csrf
                <input type="hidden" name="available" value="{{ $agent->status === \App\Domains\Delivery\Enums\AgentStatus::Active ? 0 : 1 }}">
                <x-ui.button type="submit" :variant="$agent->status === \App\Domains\Delivery\Enums\AgentStatus::Active ? 'outline' : 'primary'">
                    {{ $agent->status === \App\Domains\Delivery\Enums\AgentStatus::Active ? __('Go unavailable') : __('Go available') }}
                </x-ui.button>
            </form>
        @else
            <p class="text-sm text-fg-secondary">{{ __('Your documents are being verified.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('To deliver')" flush>
        <x-ui.table :headers="[__('Assignment'), __('Destination'), __('Status')]" :rows="$open->isNotEmpty()" :empty="__('Nothing assigned right now.')">
            @foreach ($open as $assignment)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('delivery.assignments.show', $assignment->number) }}">{{ $assignment->number }}</a></td>
                    <td class="px-5 py-3 text-xs">{{ $assignment->subOrder->order->shipping_address['city'] ?? '' }} · {{ $assignment->subOrder->order->shipping_address['area'] ?? '' }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$assignment->status" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
