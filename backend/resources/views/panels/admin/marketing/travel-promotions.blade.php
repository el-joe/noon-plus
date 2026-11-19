<x-layouts.panel :title="__('Travel promotions')" :subtitle="__('Paid placement for travel agencies: trips listed free, promoted trips first with a Sponsored label')" :breadcrumbs="[__('Growth'), __('Travel promotions')]">
    @can('travel_promotions.manage')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-ui.card :title="__('Travel placement')">
                <form method="POST" action="{{ route('admin.marketing.travel-promotions.slot') }}" class="flex flex-col gap-3" data-slot-form>
                    @csrf
                    <x-ui.input :label="__('Promoted trips shown first (slot cap)')" name="max_concurrent" type="number" min="1" max="12" :value="$slot->max_concurrent" required />
                    <input type="hidden" name="is_active" value="0">
                    <x-ui.checkbox name="is_active" value="1" :checked="$slot->is_active" :label="__('On sale in this country')" />
                    <p class="text-xs text-fg-secondary">{{ __('When more promotions are live than slots, they take turns at the top.') }}</p>
                    <div><x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button></div>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Add a promotion option')" class="lg:col-span-2">
                <form method="POST" action="{{ route('admin.marketing.travel-promotions.products.store') }}" class="grid gap-3 sm:grid-cols-2" data-product-form>
                    @csrf
                    <x-ui.input :label="__('Name (English)')" name="name_en" required />
                    <x-ui.input :label="__('Name (Arabic)')" name="name_ar" />
                    <x-ui.input :label="__('Duration (days)')" name="duration_days" type="number" min="1" max="365" required />
                    <x-ui.input :label="__('Price incl. VAT (:currency)', ['currency' => $currency])" name="price" inputmode="decimal" required />
                    <input type="hidden" name="is_active" value="1">
                    <div class="sm:col-span-2"><x-ui.button type="submit" size="sm">{{ __('Add option') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>
    @endcan

    <x-ui.card :title="__('Promotion options')" flush>
        <x-ui.table :headers="[__('Code'), __('Name'), __('Duration (days)'), __('Price incl. VAT'), __('On sale'), '']" :rows="$products->isNotEmpty()" :empty="__('No promotion options yet.')">
            @foreach ($products as $product)
                <tr class="border-t border-line" data-product="{{ $product->code }}">
                    @can('travel_promotions.manage')
                        <td class="px-5 py-3 font-mono text-xs">{{ $product->code }}</td>
                        <td class="px-5 py-3" colspan="5">
                            <form method="POST" action="{{ route('admin.marketing.travel-promotions.products.update', $product->id) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PUT')
                                <label class="sr-only" for="name-en-{{ $product->id }}">{{ __('Name (English)') }}</label>
                                <input id="name-en-{{ $product->id }}" name="name_en" value="{{ $product->name_en }}" required class="h-8 w-40 rounded-lg border border-line bg-surface px-2 text-sm">
                                <label class="sr-only" for="name-ar-{{ $product->id }}">{{ __('Name (Arabic)') }}</label>
                                <input id="name-ar-{{ $product->id }}" name="name_ar" value="{{ $product->name_ar }}" dir="rtl" class="h-8 w-40 rounded-lg border border-line bg-surface px-2 text-sm">
                                <label class="sr-only" for="days-{{ $product->id }}">{{ __('Duration (days)') }}</label>
                                <input id="days-{{ $product->id }}" name="duration_days" type="number" min="1" max="365" value="{{ $product->duration_days }}" required class="h-8 w-20 rounded-lg border border-line bg-surface px-2 text-sm">
                                <label class="sr-only" for="price-{{ $product->id }}">{{ __('Price incl. VAT') }}</label>
                                <input id="price-{{ $product->id }}" name="price" inputmode="decimal" value="{{ app(\App\Shared\Money\MoneyFactory::class)->of((string) $product->price, $currency)->getAmount() }}" required class="h-8 w-28 rounded-lg border border-line bg-surface px-2 text-sm">
                                <input type="hidden" name="is_active" value="0">
                                <label class="flex items-center gap-1 text-sm"><input type="checkbox" name="is_active" value="1" @checked($product->is_active)> {{ __('On sale') }}</label>
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button>
                            </form>
                        </td>
                    @else
                        <td class="px-5 py-3 font-mono text-xs">{{ $product->code }}</td>
                        <td class="px-5 py-3">{{ $product->name_en }}</td>
                        <td class="px-5 py-3">{{ $product->duration_days }}</td>
                        <td class="px-5 py-3"><x-ui.money :amount="(string) $product->price" :currency="$currency" /></td>
                        <td class="px-5 py-3">{{ $product->is_active ? __('Yes') : __('No') }}</td>
                        <td></td>
                    @endcan
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :title="__('Promotions')" flush>
        <form method="GET" class="flex flex-wrap items-end gap-2 px-5 pb-3">
            <x-ui.select :label="__('Status')" name="status" :options="collect($statuses)->mapWithKeys(fn ($status) => [$status->value => \App\Shared\Localization\Label::of($status)])->all()" :selected="request('status')" :placeholder="__('All')" />
            <x-ui.button type="submit" size="sm" variant="outline">{{ __('Filter') }}</x-ui.button>
        </form>
        <x-ui.table :headers="[__('Promotion'), __('Agency'), __('Trip'), __('Amount'), __('VAT'), __('Window'), __('Impressions'), __('Status'), __('Actions')]" :rows="$promotions->isNotEmpty()" :empty="__('No promotions yet.')">
            @foreach ($promotions as $promotion)
                <tr class="border-t border-line align-top" data-promotion="{{ $promotion->id }}">
                    <td class="px-5 py-3 font-medium">{{ $promotion->number }}<span class="block text-xs text-fg-secondary">{{ $promotion->product?->name_en }}</span></td>
                    <td class="px-5 py-3">{{ $promotion->vendor?->store_name }}</td>
                    <td class="px-5 py-3">{{ $promotion->package?->title_en }}</td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $promotion->amount" :currency="$promotion->currency" /></td>
                    <td class="px-5 py-3"><x-ui.money :amount="(string) $promotion->vat_amount" :currency="$promotion->currency" /></td>
                    <td class="px-5 py-3 text-xs">{{ $promotion->starts_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') ?? '—' }} → {{ $promotion->ends_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y H:i') ?? '—' }}</td>
                    <td class="px-5 py-3">{{ number_format($promotion->impressions) }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$promotion->status" />@if ($promotion->cancel_reason)<span class="block text-xs text-fg-secondary">{{ $promotion->cancel_reason }}</span>@endif</td>
                    <td class="px-5 py-3">
                        @can('travel_promotions.manage')
                            <div class="flex flex-col gap-2">
                                @if (in_array(\App\Domains\Advertising\Enums\TravelPromotionStatus::Cancelled, $promotion->status->allowedNext(), true))
                                    <form method="POST" action="{{ route('admin.marketing.travel-promotions.cancel', $promotion->id) }}" class="flex gap-2">@csrf
                                        <label class="sr-only" for="cancel-{{ $promotion->id }}">{{ __('Reason') }}</label>
                                        <input id="cancel-{{ $promotion->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Cancel without refund') }}</x-ui.button>
                                    </form>
                                @endif
                                @if ($promotion->paid_at && in_array(\App\Domains\Advertising\Enums\TravelPromotionStatus::Refunded, $promotion->status->allowedNext(), true))
                                    <form method="POST" action="{{ route('admin.marketing.travel-promotions.refund', $promotion->id) }}" class="flex gap-2">@csrf
                                        <label class="sr-only" for="refund-{{ $promotion->id }}">{{ __('Reason') }}</label>
                                        <input id="refund-{{ $promotion->id }}" name="reason" required minlength="3" placeholder="{{ __('Reason (required)') }}" class="h-8 rounded-lg border border-line bg-surface px-2 text-sm">
                                        <x-ui.button type="submit" size="sm" variant="outline">{{ __('Refund') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $promotions->links() }}
</x-layouts.panel>
