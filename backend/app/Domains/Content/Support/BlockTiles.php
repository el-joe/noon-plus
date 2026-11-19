<?php

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Shared\Http\PublicUrl;
use Illuminate\Support\Collection;

/**
 * Category tiles for navigation blocks (`tile_scroller`, `category_tiles`,
 * `category_showcase`, `tile_grid`), resolved in the page's language:
 *
 * - an item `{category: "mobiles"}` becomes that category's tile (name,
 *   image, link to its page); an item's own `label` / `image` / `caption`
 *   win; items without `category` pass through unchanged; categories that
 *   are missing or inactive are dropped;
 * - with no items, `category: "womens-fashion"` lists its sub-categories
 *   that have products (children first, then grandchildren) up to
 *   `max_items` (default 7 for `category_showcase`, 12 otherwise).
 */
class BlockTiles
{
    /**
     * @var Collection<int, Category>|null
     */
    private ?Collection $categories = null;

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>|null null when the block needs nothing resolved
     */
    public function tiles(string $type, array $settings): ?array
    {
        $key = $type === 'category_showcase' ? 'tiles' : 'items';
        $items = array_values(array_filter((array) ($settings[$key] ?? []), 'is_array'));

        if ($items !== []) {
            if (! collect($items)->contains(fn (array $item): bool => is_string($item['category'] ?? null))) {
                return null;
            }

            return collect($items)->map(fn (array $item): ?array => is_string($item['category'] ?? null) ? $this->fromItem($item) : $item)->filter()->values()->all();
        }

        $slug = is_string($settings['category'] ?? null) ? $settings['category'] : null;

        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->children($slug, min(24, max(1, (int) ($settings['max_items'] ?? ($type === 'category_showcase' ? 7 : 12)))));
    }

    /**
     * Public path of a category (`/fashion/womens-fashion/dresses`).
     */
    public function path(Category $category): string
    {
        $slugs = [];
        $current = $category;

        for ($depth = 0; $current !== null && $depth < 10; $depth++) {
            array_unshift($slugs, $current->slug);
            $current = $current->parent_id !== null ? $this->all()->get((int) $current->parent_id) : null;
        }

        return '/'.implode('/', $slugs);
    }

    public function find(string $slug): ?Category
    {
        return $this->all()->firstWhere('slug', $slug);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function fromItem(array $item): ?array
    {
        $category = $this->find((string) $item['category']);

        if ($category === null) {
            return null;
        }

        return array_filter([...$this->tile($category), ...array_filter($item, fn ($value): bool => $value !== null && $value !== '')]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function children(string $slug, int $limit): array
    {
        $root = $this->find($slug);

        if ($root === null) {
            return [];
        }

        $stocked = $this->stockedCategoryIds();
        $children = $this->all()->where('parent_id', $root->id)->sortBy('sort_order');
        $grandchildren = $this->all()->whereIn('parent_id', $children->keys()->all())->sortBy('sort_order');

        return $children->concat($grandchildren)
            ->filter(fn (Category $category): bool => $this->hasProducts($category, $stocked))
            ->take($limit)
            ->map(fn (Category $category): array => $this->tile($category))
            ->values()->all();
    }

    /**
     * @return array{label: string, image: string|null, link: string, category: string}
     */
    private function tile(Category $category): array
    {
        return ['label' => $category->name(), 'image' => PublicUrl::of($category->image_path), 'link' => $this->path($category), 'category' => $category->slug];
    }

    /**
     * @param  array<int, true>  $stocked
     */
    private function hasProducts(Category $category, array $stocked): bool
    {
        if (isset($stocked[$category->id])) {
            return true;
        }

        return $this->all()->where('parent_id', $category->id)->contains(fn (Category $child): bool => $this->hasProducts($child, $stocked));
    }

    /**
     * @return array<int, true>
     */
    private function stockedCategoryIds(): array
    {
        return Product::query()->where('status', 'active')->distinct()->pluck('category_id')
            ->filter()->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * @return Collection<int, Category>
     */
    private function all(): Collection
    {
        return $this->categories ??= Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'parent_id', 'slug', 'name_en', 'name_ar', 'image_path', 'sort_order'])->keyBy('id');
    }
}
