<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Campaigns\Support\Attribution;
use App\Domains\Cart\Models\Cart;
use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Models\Listing;

/**
 * FR-CHK-4 at checkout: contracts the customer must accept for this cart —
 * the marketer contract when a line would be credited to a marketer and
 * the published version is marked required for customers, and customer
 * purchase contracts assigned to a line's category.
 */
class CheckoutContracts
{
    public function __construct(private ContractGates $gates, private Attribution $attribution, private CountryContext $countryContext) {}

    /**
     * @return array<int, array{version_id: int, kind: string, title: string, version: int, url: string}>
     */
    public function missing(Cart $cart, int $customerUserId, ?string $visitorToken, ?string $referralCode): array
    {
        $listingIds = $cart->items()->pluck('listing_id')->map(fn ($id): int => (int) $id)->all();

        if ($listingIds === []) {
            return [];
        }

        $marketers = $this->attribution->creditedMarketers($listingIds, $customerUserId, $visitorToken, $referralCode);
        $categories = Listing::query()->whereKey($listingIds)->with('variant.product.category')->get()
            ->map(fn (Listing $listing): ?Category => $listing->variant->product->category)->filter()->unique('id')->values()->all();
        $market = $this->countryContext->current()->marketSlug('en');

        return $this->gates->missingForCustomer($customerUserId, $marketers, $categories)
            ->map(fn (ContractVersion $version): array => [
                'version_id' => $version->id,
                'kind' => $version->template->kind->value,
                'title' => $version->template->name,
                'version' => $version->version,
                'url' => route('api.v1.contracts.show', ['market' => $market, 'version' => $version->id]),
            ])->all();
    }
}
