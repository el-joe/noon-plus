<x-layouts.panel :title="__('Dispute :number', ['number' => $dispute->number])" :subtitle="__('Shipment :shipment', ['shipment' => $dispute->subOrder->number])"
                 :breadcrumbs="[__('Sales'), __('Disputes'), $dispute->number]">
    <div class="flex flex-col gap-6">
        <section class="grid gap-4 sm:grid-cols-2">
            <x-ui.stat-card :label="__('Status')" :value="str_replace('_', ' ', ucfirst($dispute->status->value))" />
            <x-ui.stat-card :label="__('Resolution')" :value="$dispute->resolution ? str_replace('_', ' ', $dispute->resolution->value) : '—'" />
        </section>
        @include('panels.shared.dispute-thread', ['timezone' => $country->timezone, 'canReply' => $canReply ?? true, 'replyAction' => route('partner.disputes.reply', $dispute->id)])
    </div>
</x-layouts.panel>
