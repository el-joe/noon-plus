<?php

namespace App\Domains\Listings\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Models\WishlistItem;
use App\Domains\Listings\Models\Listing;
use App\Domains\Panels\Support\Panel;
use App\Models\User;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;

/**
 * Stock and price emails of a listing: wishlist back-in-stock and price
 * drop to the customers who saved the product (at most {@see self::MAX_WISHLISTERS}
 * per event, each email queued), and the seller's low-stock alert (once per
 * listing per day). Sends are deferred until the stock / price change commits.
 */
class ListingEmails
{
    public const MAX_WISHLISTERS = 500;

    public function __construct(private Emails $emails, private EmailLinks $links, private CountryContext $countryContext) {}

    /**
     * `wishlist.back_in_stock` — the listing went from out of stock back to active.
     */
    public function backInStock(Listing $listing): void
    {
        $product = $this->product($listing);

        if ($product === null) {
            return;
        }

        $price = EmailValue::money($listing->effectivePrice(), $this->currency($listing));

        $this->toWishlisters($product, fn (User $customer) => $this->emails->send('wishlist.back_in_stock', $customer, [
            'product' => $this->title($product, $customer),
            'price' => $price,
        ], [
            'entity' => $product,
            'key' => 'restock:'.$listing->id.':'.now()->toDateString(),
            'url' => $this->links->storefront('products/'.$product->slug, $customer->preferredLocale()),
        ]));
    }

    /**
     * `wishlist.price_drop` — the listing's effective price was lowered (once per product per new price).
     */
    public function priceDropped(Listing $listing, string $oldPrice, string $newPrice): void
    {
        $product = $this->product($listing);

        if ($product === null) {
            return;
        }

        $currency = $this->currency($listing);

        $this->toWishlisters($product, fn (User $customer) => $this->emails->send('wishlist.price_drop', $customer, [
            'product' => $this->title($product, $customer),
            'old_price' => EmailValue::money($oldPrice, $currency),
            'price' => EmailValue::money($newPrice, $currency),
        ], [
            'entity' => $product,
            'key' => 'price:'.bcadd($newPrice, '0', 2),
            'url' => $this->links->storefront('products/'.$product->slug, $customer->preferredLocale()),
        ]));
    }

    /**
     * `seller.low_stock` — available stock fell to the threshold or below (once per listing per day).
     */
    public function lowStock(Listing $listing, int $available): void
    {
        $owner = User::query()->find($listing->vendor?->owner_user_id);
        $product = $this->product($listing);

        if ($owner === null) {
            return;
        }

        $this->emails->send('seller.low_stock', $owner, [
            'product' => $product === null ? (string) $listing->vendor_sku : $this->title($product, $owner),
            'sku' => (string) ($listing->vendor_sku ?? $listing->variant?->sku ?? ''),
            'quantity' => (string) $available,
        ], [
            'entity' => $listing,
            'key' => 'low:'.now()->toDateString(),
            'panel' => 'partner',
            'url' => Panel::find('partner')->url('products/'.$listing->id.'/edit'),
        ]);
    }

    /**
     * @param  callable(User): mixed  $send
     */
    private function toWishlisters(Product $product, callable $send): void
    {
        $customerIds = WishlistItem::query()->where('product_id', $product->id)
            ->orderBy('id')->limit(self::MAX_WISHLISTERS)->pluck('customer_user_id')->unique();

        User::query()->whereKey($customerIds)->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))->each(fn (User $customer) => $send($customer));
    }

    private function product(Listing $listing): ?Product
    {
        return $listing->variant?->product;
    }

    private function title(Product $product, User $recipient): string
    {
        return (string) ($recipient->preferredLocale() === 'ar' && filled($product->title_ar) ? $product->title_ar : $product->title_en);
    }

    private function currency(Listing $listing): string
    {
        return (string) $this->countryContext->current()->currency;
    }
}
