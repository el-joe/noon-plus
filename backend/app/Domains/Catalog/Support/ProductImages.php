<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\ProductMedia;
use App\Domains\Listings\Models\Listing;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vendor-managed product images (G-W1). A seller adds images from its
 * listing in Seller Center: for the whole product or only the listed
 * variant. Each seller manages (alt text, order, delete) only the images
 * it uploaded; the storefront shows every image of the product.
 */
class ProductImages
{
    public const MAX_PER_VENDOR_PRODUCT = 10;

    public const MAX_KB = 5120;

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, ProductMedia>
     */
    public function add(Listing $listing, array $files, ?string $altEn = null, ?string $altAr = null, bool $variantOnly = false): array
    {
        $productId = (int) $listing->variant->product_id;
        $existing = ProductMedia::query()->where('product_id', $productId)->where('vendor_id', $listing->vendor_id)->count();

        if ($existing + count($files) > self::MAX_PER_VENDOR_PRODUCT) {
            throw new TransitionRefused(__('A store can add up to :count images per product.', ['count' => self::MAX_PER_VENDOR_PRODUCT]));
        }

        $stored = [];

        try {
            return DB::connection($listing->getConnectionName())->transaction(function () use ($listing, $files, $altEn, $altAr, $variantOnly, $productId, &$stored): array {
                $order = (int) ProductMedia::query()->where('product_id', $productId)->max('sort_order');
                $media = [];

                foreach ($files as $file) {
                    $stored[] = $path = $file->store('products/'.$productId, ProductMedia::DISK);
                    [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];
                    $media[] = ProductMedia::query()->create([
                        'product_id' => $productId,
                        'product_variant_id' => $variantOnly ? $listing->product_variant_id : null,
                        'vendor_id' => $listing->vendor_id,
                        'path' => $path,
                        'alt_en' => $altEn,
                        'alt_ar' => $altAr,
                        'width' => $width === null ? null : min(65535, (int) $width),
                        'height' => $height === null ? null : min(65535, (int) $height),
                        'sort_order' => ++$order,
                    ]);
                }

                return $media;
            });
        } catch (\Throwable $failure) {
            Storage::disk(ProductMedia::DISK)->delete($stored);

            throw $failure;
        }
    }

    /**
     * @param  array{alt_en?: string|null, alt_ar?: string|null, sort_order?: int|string|null}  $data
     */
    public function update(ProductMedia $media, array $data): ProductMedia
    {
        $media->fill(array_intersect_key($data, array_flip(['alt_en', 'alt_ar', 'sort_order'])))->save();

        return $media;
    }

    public function remove(ProductMedia $media): void
    {
        $media->delete();
        Storage::disk(ProductMedia::DISK)->delete($media->path);
    }

    /**
     * The images this listing's store uploaded for the listing's product.
     *
     * @return Collection<int, ProductMedia>
     */
    public function forListing(Listing $listing): Collection
    {
        return ProductMedia::query()
            ->where('product_id', $listing->variant->product_id)
            ->where('vendor_id', $listing->vendor_id)
            ->with('variant')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The store's own image of this listing's product, or 404.
     */
    public function ownImage(Listing $listing, int $mediaId): ProductMedia
    {
        return ProductMedia::query()
            ->where('product_id', $listing->variant->product_id)
            ->where('vendor_id', $listing->vendor_id)
            ->findOrFail($mediaId);
    }
}
