<x-layouts.panel :title="__('Approvals queue')" :subtitle="__('Listings waiting for review in :country · times in Oman time', ['country' => $country->name_en])"
                 :breadcrumbs="[__('Overview'), __('Approvals queue')]">
    <nav class="flex gap-4 border-b border-line text-sm" aria-label="{{ __('Queues') }}">
        <span class="border-b-2 border-brand pb-2 font-semibold">{{ __('Listings (:count)', ['count' => $listings->total()]) }}</span>
    </nav>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Item'), __('Seller'), __('Price'), __('Stock'), __('Submitted (Oman)'), __('Decision')]" :rows="$listings->isNotEmpty()" :empty="__('Nothing waiting for review.')">
            @foreach ($listings as $listing)
                <tr class="border-t border-line align-top" data-listing="{{ $listing->id }}">
                    <td class="px-5 py-3">
                        <span class="font-medium">{{ $listing->variant->product->title() }}</span>
                        <span class="block text-xs text-fg-secondary">{{ $listing->variant->sku }} · {{ $listing->variant->product->category->name() }} · {{ ucfirst($listing->condition) }} · {{ strtoupper($listing->fulfilment) }}</span>
                    </td>
                    <td class="px-5 py-3">{{ $listing->vendor->store_name }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="$listing->effectivePrice()" :currency="$country->currency" /></td>
                    <td class="px-5 py-3">{{ (int) $listing->available_quantity }}</td>
                    <td class="px-5 py-3 text-xs">{{ $listing->submitted_at ? $clock->inAdminTime(\Carbon\CarbonImmutable::parse($listing->submitted_at, 'UTC'))->format('j M H:i') : '—' }}</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-col gap-2">
                            <form method="POST" action="{{ route('admin.approvals.approve', $listing->id) }}">
                                @csrf
                                <x-ui.button type="submit" size="sm">{{ __('Approve') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('admin.approvals.reject', $listing->id) }}" class="flex gap-2">
                                @csrf
                                <label class="sr-only" for="reason-{{ $listing->id }}">{{ __('Reject reason') }}</label>
                                <input id="reason-{{ $listing->id }}" name="reason" required minlength="5" placeholder="{{ __('Reason (required)') }}"
                                       class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Reject') }}</x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @error('reason')<p class="text-sm text-fg-danger">{{ $message }}</p>@enderror

    {{ $listings->links() }}
</x-layouts.panel>
