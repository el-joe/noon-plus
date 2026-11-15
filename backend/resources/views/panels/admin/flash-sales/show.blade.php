<x-layouts.panel :title="$event->name"
                 :subtitle="__(':start → :end Oman time · max :max per customer', [
                     'start' => $clock->inAdminTime($event->starts_at)->format('j M H:i'),
                     'end' => $clock->inAdminTime($event->ends_at)->format('j M H:i'),
                     'max' => $event->max_qty_per_customer,
                 ])"
                 :breadcrumbs="[__('Coupons & flash sales'), __('Flash sales'), $event->name]">
    <x-slot:actions><x-ui.status-badge :status="$event->status" /></x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card :title="__('Seller offers')" flush class="xl:col-span-2">
            <x-ui.table :headers="[__('Listing'), __('Seller'), __('Was'), __('Flash'), __('Units'), __('Status'), __('Decision')]" :rows="$event->submissions->isNotEmpty()" :empty="__('No offers yet.')">
                @foreach ($event->submissions as $submission)
                    <tr class="border-t border-line align-top" data-submission="{{ $submission->id }}">
                        <td class="px-5 py-3">{{ $submission->listing->variant->product->title() }}</td>
                        <td class="px-5 py-3">{{ $submission->listing->vendor->store_name }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="$submission->original_price" :currency="$country->currency" /></td>
                        <td class="px-5 py-3"><x-ui.money :amount="$submission->flash_price" :currency="$country->currency" /></td>
                        <td class="px-5 py-3">{{ $submission->sold_quantity }} / {{ $submission->quantity_cap }}</td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$submission->status" /></td>
                        <td class="px-5 py-3">
                            @can('flash_sales.manage')
                                @if ($submission->status === \App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus::UnderReview)
                                    <div class="flex flex-col gap-2">
                                        <form method="POST" action="{{ route('admin.flash-sales.submissions.approve', $submission->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.flash-sales.submissions.reject', $submission->id) }}" class="flex gap-2">
                                            @csrf
                                            <input name="reason" required minlength="5" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reject reason') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
                                        </form>
                                    </div>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div class="flex flex-col gap-6">
            @can('flash_sales.manage')
                @if ($event->status->allowedNext() !== [])
                    <x-ui.card :title="__('Move event')">
                        <form method="POST" action="{{ route('admin.flash-sales.transition', $event->id) }}" class="flex flex-col gap-3">
                            @csrf
                            <x-ui.select :label="__('Next status')" name="to"
                                         :options="collect($event->status->allowedNext())->mapWithKeys(fn ($status) => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()" />
                            <x-ui.textarea :label="__('Reason (required to cancel)')" name="reason" rows="2" />
                            <div><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            <x-ui.state-timeline :title="__('Event history')" :subtitle="__('Oman time')"
                                 :steps="$event->statusHistory->map(fn ($change) => [
                                     'label' => str_replace('_', ' ', ucfirst($change->to_status->value)),
                                     'meta' => ucfirst($change->actor_type->value).' · '.$clock->inAdminTime($change->created_at)->format('j M H:i').($change->reason ? ' · '.$change->reason : ''),
                                     'state' => $change->to_status === \App\Domains\FlashSales\Enums\FlashSaleEventStatus::Cancelled ? 'stopped' : 'done',
                                 ])->all()" />
        </div>
    </div>
</x-layouts.panel>
