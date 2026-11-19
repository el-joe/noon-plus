<x-layouts.panel :title="$listing->variant->product->title()"
                 :subtitle="$listing->variant->sku.' · '.ucfirst($listing->condition).' · '.strtoupper($listing->fulfilment)"
                 :breadcrumbs="[__('Products'), __('Manage listing')]">
    <x-slot:actions>
        <x-ui.status-badge :status="$listing->status" />
        @php($next = $canWrite ? $listing->status->allowedNext() : [])
        @foreach ([
            'submit' => [\App\Domains\Listings\Enums\ListingStatus::PendingReview, __('Submit for review'), 'primary'],
            'pause' => [\App\Domains\Listings\Enums\ListingStatus::Paused, __('Pause'), 'outline'],
            'resume' => [\App\Domains\Listings\Enums\ListingStatus::Active, __('Resume'), 'primary'],
            'archive' => [\App\Domains\Listings\Enums\ListingStatus::Archived, __('Archive'), 'ghost'],
        ] as $action => [$target, $label, $variant])
            @if (in_array($target, $next, true) && ($action !== 'resume' || $listing->status === \App\Domains\Listings\Enums\ListingStatus::Paused))
                <form method="POST" action="{{ route('partner.products.status', $listing->id) }}">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <x-ui.button type="submit" :variant="$variant" size="sm">{{ $label }}</x-ui.button>
                </form>
            @endif
        @endforeach
    </x-slot:actions>

    @if ($listing->rejection_reason && $listing->status === \App\Domains\Listings\Enums\ListingStatus::Rejected)
        <div role="alert" class="rounded-lg bg-danger-subtle px-4 py-3 text-sm text-fg-danger">{{ __('Rejected: :reason', ['reason' => $listing->rejection_reason]) }}</div>
    @endif

    @unless ($canWrite)
        <div role="status" class="rounded-lg bg-info-subtle px-4 py-3 text-sm" data-read-only>{{ __('Your team role can view this listing but not change it. Ask the owner or a catalog manager.') }}</div>
    @endunless

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Price & handling')">
                @if ($canWrite)
                <form method="POST" action="{{ route('partner.products.update', $listing->id) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <x-ui.input :label="__('Price (VAT included)')" name="price" :value="rtrim(rtrim((string) $listing->price, '0'), '.')" required />
                    <x-ui.input :label="__('Sale price')" name="sale_price" :value="$listing->sale_price ? rtrim(rtrim((string) $listing->sale_price, '0'), '.') : ''" />
                    <x-ui.input :label="__('Handling days')" name="handling_days" type="number" min="0" max="30" :value="$listing->handling_days" required />
                    <x-ui.input :label="__('Your SKU')" name="vendor_sku" :value="$listing->vendor_sku" />
                    <div class="flex justify-end sm:col-span-2"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button></div>
                </form>
                @else
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-fg-secondary">{{ __('Price (VAT included)') }}</dt><dd class="font-medium"><x-ui.money :amount="(string) $listing->price" :currency="$currency" /></dd></div>
                        <div><dt class="text-fg-secondary">{{ __('Sale price') }}</dt><dd class="font-medium">@if ($listing->sale_price)<x-ui.money :amount="(string) $listing->sale_price" :currency="$currency" />@else — @endif</dd></div>
                        <div><dt class="text-fg-secondary">{{ __('Handling days') }}</dt><dd class="font-medium">{{ $listing->handling_days }}</dd></div>
                        <div><dt class="text-fg-secondary">{{ __('Your SKU') }}</dt><dd class="font-medium">{{ $listing->vendor_sku ?: '—' }}</dd></div>
                    </dl>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Product images')" :subtitle="__(':count of :max images from your store · lower position shows first · EN/AR alt text', ['count' => $images->count(), 'max' => $maxImages])">
                @if ($images->isNotEmpty())
                    <ul class="mb-4 grid gap-3 sm:grid-cols-2">
                        @foreach ($images as $image)
                            <li class="flex gap-3 rounded-lg border border-line p-3" data-image="{{ $image->id }}">
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_en }}" class="size-20 shrink-0 rounded object-cover">
                                <div class="flex flex-1 flex-col gap-2">
                                    @if ($canWrite)
                                    <form method="POST" action="{{ route('partner.products.images.update', [$listing->id, $image->id]) }}" class="grid gap-2">
                                        @csrf
                                        @method('PUT')
                                        <input name="alt_en" value="{{ $image->alt_en }}" placeholder="{{ __('Alt text (English)') }}" aria-label="{{ __('Alt text (English)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <input name="alt_ar" value="{{ $image->alt_ar }}" dir="rtl" placeholder="{{ __('Alt text (Arabic)') }}" aria-label="{{ __('Alt text (Arabic)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <div class="flex items-center gap-2">
                                            <input name="sort_order" type="number" min="0" max="9999" value="{{ $image->sort_order }}" aria-label="{{ __('Position') }}" class="h-8 w-20 rounded-lg border border-line bg-surface px-2 text-sm">
                                            <span class="text-xs text-fg-secondary">{{ $image->product_variant_id ? __('This variant only') : __('All variants') }}</span>
                                            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('partner.products.images.destroy', [$listing->id, $image->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Remove') }}</x-ui.button>
                                    </form>
                                    @else
                                        <p class="text-sm">{{ $image->alt_en ?: '—' }}</p>
                                        <p class="text-sm" dir="rtl">{{ $image->alt_ar ?: '—' }}</p>
                                        <p class="text-xs text-fg-secondary">{{ __('Position') }} {{ $image->sort_order }} · {{ $image->product_variant_id ? __('This variant only') : __('All variants') }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canWrite && $images->count() < $maxImages)
                    <form method="POST" action="{{ route('partner.products.images.store', $listing->id) }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <div class="flex flex-col gap-1.5 sm:col-span-2">
                            <label for="images" class="text-sm font-medium text-fg">{{ __('Add images') }}</label>
                            <input id="images" name="images[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" class="rounded-lg border border-line bg-surface px-3 py-2 text-sm">
                            @error('images')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                            @error('images.*')<p class="text-xs text-fg-danger">{{ $message }}</p>@enderror
                        </div>
                        <x-ui.input :label="__('Alt text (English)')" name="alt_en" />
                        <x-ui.input :label="__('Alt text (Arabic)')" name="alt_ar" dir="rtl" />
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="variant_only" value="1"> {{ __('Only for :sku', ['sku' => $listing->variant->sku]) }}</label>
                        <div class="flex justify-end"><x-ui.button type="submit" variant="outline">{{ __('Upload') }}</x-ui.button></div>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Stock')" :subtitle="__(':count units available · every change is recorded with a reason', ['count' => $available])">
                @if ($canWrite)
                <form method="POST" action="{{ route('partner.products.stock', $listing->id) }}" class="grid gap-4 sm:grid-cols-3">
                    @csrf
                    <x-ui.input :label="__('Change (+ add, − remove)')" name="quantity" type="number" required />
                    <x-ui.select :label="__('Reason')" name="reason"
                                 :options="collect(config('nawy.vendors.stock_reasons'))->mapWithKeys(fn ($reason) => [$reason => ucfirst($reason)])->all()" />
                    <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Update stock') }}</x-ui.button></div>
                </form>
                @endif
                <x-ui.table class="mt-4" :headers="[__('When'), __('Change'), __('Reason')]" :rows="$movements->isNotEmpty()">
                    @foreach ($movements as $movement)
                        <tr class="border-t border-line">
                            <td class="px-5 py-2 text-xs">{{ $movement->created_at->setTimezone($currentCountry->timezone)->format('j M H:i') }}</td>
                            <td class="px-5 py-2">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                            <td class="px-5 py-2">{{ str_replace('_', ' ', $movement->reason) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.state-timeline :title="__('Status history')" :subtitle="__('Times in :zone', ['zone' => $currentCountry->timezone])"
                             :steps="$listing->statusHistory->map(fn ($change) => [
                                 'label' => str_replace('_', ' ', ucfirst($change->to_status->value)),
                                 'meta' => $change->created_at->setTimezone($currentCountry->timezone)->format('j M H:i').($change->reason ? ' · '.$change->reason : ''),
                                 'state' => 'done',
                             ])->all()" />
    </div>
</x-layouts.panel>
