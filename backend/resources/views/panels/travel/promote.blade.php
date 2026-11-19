<x-layouts.panel :title="__('Promote a trip')" :subtitle="__('Your trip shows first in travel lists and search on the website and app, with a Sponsored label.')" :breadcrumbs="[__('Travel Partner'), __('Promotions'), __('Promote a trip')]">
    @if (! app(\App\Domains\Panels\Support\TenantPermissions::class)->allows(request(), 'travel_agency', 'promotions', true))
        <x-ui.card>
            <p class="text-sm" data-read-only>{{ __('Your team role can see promotions but not buy them. Ask the owner or an Operations member.') }}</p>
        </x-ui.card>
    @elseif ($packages->isEmpty())
        <x-ui.card>
            <p class="text-sm" data-no-active-trips>{{ __('You have no active trip to promote. A trip can be promoted once it is approved and on sale.') }}</p>
        </x-ui.card>
    @elseif ($products->isEmpty())
        <x-ui.card>
            <p class="text-sm" data-no-products>{{ __('Promotions are not on sale in this country right now.') }}</p>
        </x-ui.card>
    @else
        <form method="POST" action="{{ route('travel_agency.promotions.store') }}" class="flex flex-col gap-4" data-promote-form>
            @csrf
            <x-ui.card :title="__('1. Choose a trip')">
                <x-ui.select :label="__('Trip')" name="package_id" :options="$packages->mapWithKeys(fn ($package) => [$package->id => $package->localizedTitle().' · '.$package->destination_city])->all()" :selected="$selected" required />
            </x-ui.card>

            <x-ui.card :title="__('2. Choose how long')">
                <fieldset class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <legend class="sr-only">{{ __('Promotion option') }}</legend>
                    @foreach ($products as $product)
                        <label class="flex cursor-pointer flex-col gap-1 rounded-lg border border-line p-4 has-[:checked]:border-brand has-[:checked]:bg-brand/5" data-product="{{ $product->code }}">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="product_id" value="{{ $product->id }}" required @checked((string) old('product_id', $loop->first ? $product->id : '') === (string) $product->id)>
                                <span class="font-semibold">{{ $product->localizedName() }}</span>
                            </span>
                            <span class="text-sm text-fg-secondary">{{ trans_choice(':count day|:count days', $product->duration_days, ['count' => $product->duration_days]) }}</span>
                            <x-ui.money class="text-lg font-bold" :amount="(string) $product->price" :currency="$currency" />
                        </label>
                    @endforeach
                </fieldset>
                <p class="mt-3 text-xs text-fg-secondary">{{ __('Prices include VAT (:rate%). You get a tax receipt once paid.', ['rate' => rtrim(rtrim($vatRate, '0'), '.')]) }}</p>
            </x-ui.card>

            <x-ui.card :title="__('3. Pay by card')">
                <p class="text-sm">{{ __('You will go to the secure card page of our payment provider, then come back here. The promotion starts as soon as the payment is confirmed.') }}</p>
                <p class="mt-2 text-xs text-fg-secondary">{{ __('Listing trips on Nawy stays free, with no commission. Customers still book without paying on Nawy.') }}</p>
                <div class="mt-4"><x-ui.button type="submit">{{ __('Pay by card') }}</x-ui.button></div>
            </x-ui.card>
        </form>
    @endif
</x-layouts.panel>
