<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The products' search index (QC-015 "search index rebuild"): one
 * normalised text per product in `products.search_text` — English and
 * Arabic titles, model number, brand and category names (both languages),
 * variant SKUs and barcodes — lower-cased, with Arabic diacritics and
 * tatweel removed and letter variants folded (أ إ آ ٱ → ا, ى → ي, ة → ه,
 * ؤ → و, ئ → ي), so "مكينة قهوة" finds "ماكينة القهوة"-style spellings
 * that only differ in those letters. Written when a product or variant is
 * saved and rebuilt nightly by `nawy:search:rebuild-index`;
 * {@see ProductSearch} matches `q` against it in addition to the titles.
 */
class SearchText
{
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function forProduct(Product $product): string
    {
        $brand = $product->brand_id ? DB::connection($product->getConnectionName())->table('brands')->where('id', $product->brand_id)->first(['name', 'name_ar']) : null;
        $category = $product->category_id ? DB::connection($product->getConnectionName())->table('categories')->where('id', $product->category_id)->first(['name_en', 'name_ar']) : null;
        $variants = $product->exists ? DB::connection($product->getConnectionName())->table('product_variants')->where('product_id', $product->id)->get(['sku', 'barcode']) : collect();

        $parts = [
            $product->title_en, $product->title_ar, $product->model_number,
            $brand?->name, $brand?->name_ar, $category?->name_en, $category?->name_ar,
            ...$variants->pluck('sku')->all(), ...$variants->pluck('barcode')->filter()->all(),
        ];

        return mb_substr(self::normalise(implode(' ', array_filter($parts, fn ($part): bool => filled($part)))), 0, 60000);
    }

    /**
     * Rebuild every product of the current country; returns how many rows changed.
     */
    public function rebuild(): int
    {
        $changed = 0;

        Product::query()->orderBy('id')->chunkById(500, function ($products) use (&$changed): void {
            foreach ($products as $product) {
                $text = self::forProduct($product);

                if ($text !== $product->search_text) {
                    $changed++;
                }

                Product::query()->whereKey($product->id)->toBase()->update(['search_text' => $text, 'search_indexed_at' => now()]);
            }
        });

        return $changed;
    }

    public static function refresh(int $productId): void
    {
        $product = Product::query()->find($productId);

        if ($product !== null) {
            Product::query()->whereKey($productId)->toBase()->update(['search_text' => self::forProduct($product), 'search_indexed_at' => now()]);
        }
    }
}
