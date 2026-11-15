@php($status = $assignment->status)
@php($S = \App\Domains\Delivery\Enums\AssignmentStatus::class)
@php($address = $assignment->subOrder->order->shipping_address)
<x-layouts.panel :title="$assignment->number" :subtitle="__('Attempt :attempt · :seller', ['attempt' => $assignment->attempt_no, 'seller' => $assignment->subOrder->vendor?->store_name])" :breadcrumbs="[__('Today'), __('Assignments')]">
    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Delivery code')" :subtitle="__('Ask the customer to read their code aloud. Tap “Codes match” only if it is the same.')">
            @if ($status->isOpen())
                <p class="text-center font-mono text-5xl font-bold tracking-[0.4em]" data-delivery-code>{{ $assignment->delivery_code }}</p>
            @else
                <p class="text-sm text-fg-secondary">{{ __('This assignment is closed.') }} <x-ui.status-badge :status="$status" /></p>
            @endif
            @if ($assignment->cod_amount !== null && $status->isOpen())
                <p class="mt-4 rounded-lg bg-warning-subtle px-3 py-2 text-sm text-fg-warning">{{ __('Collect cash:') }} <x-ui.money :amount="$assignment->cod_amount" :currency="$assignment->currency" /></p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Customer')" class="lg:col-span-2">
            @if ($status->isOpen())
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-fg-secondary">{{ __('Name') }}</dt><dd>{{ $address['name'] ?? '' }}</dd></div>
                    <div><dt class="text-fg-secondary">{{ __('Phone') }}</dt><dd>{{ $address['phone'] ?? '' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Address') }}</dt><dd>{{ collect($address)->except(['name', 'phone'])->filter()->implode(', ') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-fg-secondary">{{ __('Items') }}</dt><dd>{{ $assignment->subOrder->items->map(fn ($item) => $item->quantity.' × '.$item->title)->implode(', ') }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-fg-secondary">{{ __('Customer details are hidden once the assignment is closed.') }}</p>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Next step')">
        <div class="flex flex-wrap items-start gap-4">
            @if ($status === $S::Assigned)
                <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'accept']) }}">@csrf<x-ui.button type="submit">{{ __('Accept') }}</x-ui.button></form>
                <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'decline']) }}" class="flex items-end gap-2">@csrf
                    <x-ui.input :label="__('Reason to decline')" name="reason" /><x-ui.button type="submit" variant="outline">{{ __('Decline') }}</x-ui.button></form>
            @elseif ($status === $S::Accepted)
                <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'start']) }}">@csrf<x-ui.button type="submit">{{ __('Picked up — start delivery') }}</x-ui.button></form>
            @elseif ($status === $S::PickedUp)
                @if (! $assignment->arrived_at)
                    <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'arrive']) }}">@csrf<x-ui.button type="submit" variant="outline">{{ __('I have arrived') }}</x-ui.button></form>
                @endif
                <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'confirm']) }}" enctype="multipart/form-data" class="flex flex-col gap-3 rounded-lg border border-line p-4" data-confirm-form>
                    @csrf
                    <input type="hidden" name="codes_match" value="1">
                    @if ($needsProof)
                        <p class="text-sm font-medium">{{ $proofType === 'signature' ? __('Signature required') : ($proofType === 'photo' ? __('Proof photo required') : __('Proof photo or signature required')) }}
                            @if ($assignment->hasProof())<x-ui.badge tone="success">{{ __('Proof added') }}</x-ui.badge>@endif</p>
                    @endif
                    @if ($proofType !== 'signature')
                        <x-ui.input :label="__('Proof photo')" name="photo" type="file" accept="image/*" capture="environment" />
                    @endif
                    @if ($proofType !== 'photo')
                        <div class="flex flex-col gap-1.5">
                            <span class="text-sm font-medium">{{ __('Customer signature') }}</span>
                            <canvas width="320" height="140" class="touch-none rounded-lg border border-line bg-white" data-signature-pad></canvas>
                            <input type="hidden" name="signature_data" data-signature-value>
                            <button type="button" class="self-start text-xs text-fg-link" data-signature-clear>{{ __('Clear') }}</button>
                        </div>
                    @endif
                    <x-ui.button type="submit">{{ __('Codes match — delivered') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('delivery.assignments.act', [$assignment->number, 'fail']) }}" class="flex flex-col gap-3 rounded-lg border border-line p-4">
                    @csrf
                    <x-ui.select :label="__('Can\'t deliver')" name="reason" :options="collect($reasons)->mapWithKeys(fn ($reason) => [$reason => str_replace('_', ' ', ucfirst($reason))])->all()" />
                    <x-ui.input :label="__('Note')" name="note" />
                    <x-ui.button type="submit" variant="danger">{{ __('Report failed attempt') }}</x-ui.button>
                </form>
            @else
                <p class="text-sm text-fg-secondary">{{ __('Nothing left to do.') }}</p>
            @endif
        </div>
    </x-ui.card>

    <script>
        document.querySelectorAll('[data-signature-pad]').forEach((canvas) => {
            const context = canvas.getContext('2d');
            const field = canvas.parentElement.querySelector('[data-signature-value]');
            let drawing = false;
            let signed = false;
            const point = (event) => {
                const box = canvas.getBoundingClientRect();
                return [(event.clientX - box.left) * canvas.width / box.width, (event.clientY - box.top) * canvas.height / box.height];
            };
            context.lineWidth = 2;
            context.lineCap = 'round';
            canvas.addEventListener('pointerdown', (event) => { drawing = true; context.beginPath(); context.moveTo(...point(event)); });
            canvas.addEventListener('pointermove', (event) => { if (drawing) { context.lineTo(...point(event)); context.stroke(); signed = true; } });
            window.addEventListener('pointerup', () => { if (drawing) { drawing = false; field.value = signed ? canvas.toDataURL('image/png') : ''; } });
            canvas.parentElement.querySelector('[data-signature-clear]').addEventListener('click', () => {
                context.clearRect(0, 0, canvas.width, canvas.height);
                signed = false;
                field.value = '';
            });
        });
    </script>
</x-layouts.panel>
