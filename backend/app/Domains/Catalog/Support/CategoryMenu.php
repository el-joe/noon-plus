<?php

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Shared\Http\PublicCache;
use App\Shared\Http\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Header mega menu (`GET /api/v1/{market}/catalog/menu`): every active
 * top-level category with its active level-2 sub-categories (columns) and
 * their active level-3 sub-categories (links), the category image and the
 * top brands that sell in it. Level-2 / level-3 categories without products
 * (descendants included) are left out to keep the menu clean; a top-level
 * category is always listed so the nav bar and the menu stay in step.
 *
 * One grouped count (category × brand over active products with a sellable
 * offer) feeds every number, then the tree is folded in PHP. The answer is
 * cached per country and locale under {@see PublicCache::CATALOG_MENU}: a
 * category or brand change flushes it at once, product / listing changes
 * show within the TTL.
 */
class CategoryMenu
{
    public const TTL_SECONDS = 300;

    public const TOP_BRANDS = 8;

    public function __construct(private ProductSearch $search) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cached(string $locale): array
    {
        return PublicCache::remember(PublicCache::countryGroup(PublicCache::CATALOG_MENU), 'menu:'.$locale, self::TTL_SECONDS, fn (): array => $this->build($locale === 'ar'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(bool $arabic): array
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'parent_id', 'slug', 'name_en', 'name_ar', 'image_path']);
        $children = $categories->groupBy(fn (Category $category): int => (int) $category->parent_id);

        // product counts per (category, brand); brand 0 = no brand.
        $rows = $this->search->query([])->toBase()
            ->select('products.category_id', 'products.brand_id', DB::raw('count(*) as total'))
            ->groupBy('products.category_id', 'products.brand_id')
            ->get();
        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[(int) $row->category_id][(int) $row->brand_id] = ($byCategory[(int) $row->category_id][(int) $row->brand_id] ?? 0) + (int) $row->total;
        }

        /** Brand counts of a category and every active category below it. */
        $brandCounts = function (Category $category, int $depth = 0) use (&$brandCounts, $children, $byCategory): array {
            $counts = $byCategory[$category->id] ?? [];

            if ($depth < 10) {
                foreach ($children->get($category->id, collect()) as $child) {
                    foreach ($brandCounts($child, $depth + 1) as $brand => $total) {
                        $counts[$brand] = ($counts[$brand] ?? 0) + $total;
                    }
                }
            }

            return $counts;
        };

        $name = fn (Category $category): string => $arabic ? ($category->name_ar ?: $category->name_en) : $category->name_en;
        $roots = $children->get(0, collect());
        $rootBrands = $roots->mapWithKeys(fn (Category $root): array => [$root->id => $brandCounts($root)]);
        $brands = $this->brands($rootBrands->flatMap(fn (array $counts): array => array_keys($counts))->unique()->reject(fn (int $id): bool => $id === 0)->values());

        return $roots->map(function (Category $root) use ($children, $brandCounts, $name, $rootBrands, $brands, $arabic): array {
            $columns = $children->get($root->id, collect())->map(function (Category $level2) use ($root, $children, $brandCounts, $name): ?array {
                $count = array_sum($brandCounts($level2));

                if ($count === 0) {
                    return null;
                }

                $links = $children->get($level2->id, collect())
                    ->map(fn (Category $level3): array => ['category' => $level3, 'count' => array_sum($brandCounts($level3))])
                    ->filter(fn (array $item): bool => $item['count'] > 0)
                    ->map(fn (array $item): array => [
                        'id' => $item['category']->id,
                        'slug' => $item['category']->slug,
                        'name' => $name($item['category']),
                        'path' => '/'.$root->slug.'/'.$item['category']->slug,
                        'product_count' => $item['count'],
                    ])->values()->all();

                return [
                    'id' => $level2->id,
                    'slug' => $level2->slug,
                    'name' => $name($level2),
                    'path' => '/'.$root->slug.'/'.$level2->slug,
                    'image' => PublicUrl::of($level2->image_path),
                    'product_count' => $count,
                    'children' => $links,
                ];
            })->filter()->values();

            $counts = $rootBrands[$root->id];
            $top = collect($counts)->except([0])->filter(fn (int $total, int $id): bool => $brands->has($id))
                ->sortDesc()->take(self::TOP_BRANDS)
                ->map(fn (int $total, int $id): array => [
                    'slug' => $brands[$id]->slug,
                    'name' => $arabic ? ($brands[$id]->name_ar ?: $brands[$id]->name) : $brands[$id]->name,
                    'logo' => PublicUrl::of($brands[$id]->logo_path),
                    'path' => '/'.$root->slug.'?brand='.rawurlencode($brands[$id]->slug),
                    'product_count' => $total,
                ])->values()->all();

            return [
                'id' => $root->id,
                'slug' => $root->slug,
                'name' => $name($root),
                'path' => '/'.$root->slug,
                'image' => PublicUrl::of($root->image_path) ?? $columns->pluck('image')->filter()->first(),
                'product_count' => array_sum($counts),
                'children' => $columns->all(),
                'brands' => $top,
            ];
        })->values()->all();
    }

    /**
     * Active brands by id.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Brand>
     */
    private function brands(Collection $ids): Collection
    {
        return $ids->isEmpty()
            ? collect()
            : Brand::query()->where('is_active', true)->whereIn('id', $ids)->get(['id', 'slug', 'name', 'name_ar', 'logo_path'])->keyBy('id');
    }
}
