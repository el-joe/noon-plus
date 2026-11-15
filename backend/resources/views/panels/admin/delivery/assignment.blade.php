@php($fmt = fn ($moment) => $moment ? $clock->inAdminTime($moment)->format('j M Y, H:i') : '—')
<x-layouts.panel :title="$assignment->number" :subtitle="__('Attempt :attempt of :parcel · times in Oman time', ['attempt' => $assignment->attempt_no, 'parcel' => $assignment->subOrder->number])" :breadcrumbs="[__('Operations'), __('Delivery & logistics'), __('Dispatch')]">
    @include('panels.admin.delivery._nav')

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Assignment')" class="lg:col-span-2">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-fg-secondary">{{ __('Status') }}</dt><dd><x-ui.status-badge :status="$assignment->status" /></dd></div>
                <div><dt class="text-fg-secondary">{{ __('Delivery code (shown to agent and customer)') }}</dt><dd class="font-mono text-lg font-bold tracking-widest" data-delivery-code>{{ $assignment->delivery_code }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Agent') }}</dt><dd>{{ $assignment->agent->name }} @if ($assignment->carrier)· {{ $assignment->carrier->name }}@endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Seller') }}</dt><dd>{{ $assignment->subOrder->vendor?->store_name }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('COD to collect') }}</dt><dd>@if ($assignment->cod_amount !== null)<x-ui.money :amount="$assignment->cod_amount" :currency="$assignment->currency" />@else {{ __('Prepaid') }} @endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Proof required') }}</dt><dd>{{ $needsProof ? __('Yes') : __('No') }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('SLA due') }}</dt><dd>{{ $fmt($assignment->sla_due_at) }} @if ($assignment->sla_breached_at)<x-ui.badge tone="danger">{{ __('Breached') }}</x-ui.badge>@endif</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Picked up / arrived') }}</dt><dd>{{ $fmt($assignment->picked_up_at) }} / {{ $fmt($assignment->arrived_at) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Delivered / failed') }}</dt><dd>{{ $fmt($assignment->delivered_at) }} / {{ $fmt($assignment->failed_at) }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Failure') }}</dt><dd>{{ $assignment->failure_reason ? str_replace('_', ' ', $assignment->failure_reason) : '—' }} {{ $assignment->failure_note }}</dd></div>
                <div><dt class="text-fg-secondary">{{ __('Proof') }}</dt><dd class="flex gap-3">
                    @if ($assignment->proof_photo_path)<a class="text-fg-link" href="{{ route('admin.delivery.assignments.proof', [$assignment->id, 'photo']) }}">{{ __('Photo') }}</a>@endif
                    @if ($assignment->signature_path)<a class="text-fg-link" href="{{ route('admin.delivery.assignments.proof', [$assignment->id, 'signature']) }}">{{ __('Signature') }}</a>@endif
                    @if (! $assignment->hasProof()) — @endif
                </dd></div>
                <div><dt class="text-fg-secondary">{{ __('Shipment') }}</dt><dd>{{ $assignment->shipment->number }} · <x-ui.status-badge :status="$assignment->shipment->status" /></dd></div>
            </dl>
        </x-ui.card>

        <div class="flex flex-col gap-6">
            <x-ui.state-timeline :title="__('Assignment history')" :steps="$history->map(fn ($row) => ['state' => 'done', 'label' => str_replace('_', ' ', ucfirst($row->to_status)), 'meta' => ucfirst((string) $row->actor_type).' · '.$clock->inAdminTime($row->created_at)->format('j M H:i').($row->reason ? ' · '.$row->reason : '')])->all()" />
            <x-ui.state-timeline :title="__('Shipment history')" :steps="$shipmentHistory->map(fn ($row) => ['state' => 'done', 'label' => str_replace('_', ' ', ucfirst($row->to_status)), 'meta' => ucfirst((string) $row->actor_type).' · '.$clock->inAdminTime($row->created_at)->format('j M H:i').($row->reason ? ' · '.$row->reason : '')])->all()" />

            @can('delivery_assignments.manage')
                @if (in_array($assignment->status, [\App\Domains\Delivery\Enums\AssignmentStatus::Assigned, \App\Domains\Delivery\Enums\AssignmentStatus::Accepted], true))
                    <x-ui.card :title="__('Reassign')" :subtitle="__('The customer gets a new delivery code')">
                        <form method="POST" action="{{ route('admin.delivery.assignments.reassign', $assignment->id) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.select :label="__('New agent')" name="agent_id" :options="$agents->pluck('name', 'id')->all()" :placeholder="__('Agent…')" />
                            <x-ui.input :label="__('Reason')" name="reason" />
                            <x-ui.button type="submit">{{ __('Reassign') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan
        </div>
    </div>
</x-layouts.panel>
