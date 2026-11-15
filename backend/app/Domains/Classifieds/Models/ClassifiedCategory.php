<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A dynamic classified category (FR-CLS-3): optional website section and vertical inherit from the parent; attributes inherit down the tree; the contract flag decides whether the seller signs before submission.
 */
#[Fillable(['parent_id', 'slug', 'name_en', 'name_ar', 'section', 'vertical', 'requires_contract', 'listing_duration_days', 'renewal_price', 'max_photos', 'allows_video', 'is_active', 'sort_order'])]
class ClassifiedCategory extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'requires_contract' => false, 'allows_video' => true, 'max_photos' => 20, 'vertical' => 'general', 'sort_order' => 0];

    protected $table = 'classified_categories';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_contract' => 'boolean',
            'allows_video' => 'boolean',
            'is_active' => 'boolean',
            'renewal_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ClassifiedCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ClassifiedAttribute, $this>
     */
    public function ownAttributes(): HasMany
    {
        return $this->hasMany(ClassifiedAttribute::class);
    }

    /**
     * This category followed by its parents, nearest first.
     *
     * @return array<int, ClassifiedCategory>
     */
    public function lineage(): array
    {
        $lineage = [];
        $category = $this;

        while ($category !== null && count($lineage) < 10) {
            $lineage[] = $category;
            $category = $category->parent;
        }

        return $lineage;
    }

    /**
     * The attribute schema: the category's own fields plus those inherited
     * from its parents (a child redefining a key wins).
     *
     * @return Collection<int, ClassifiedAttribute>
     */
    public function attributeSchema(): Collection
    {
        $fields = collect();

        foreach (array_reverse($this->lineage()) as $category) {
            foreach ($category->ownAttributes()->orderBy('sort_order')->orderBy('id')->get() as $attribute) {
                $fields->put($attribute->key, $attribute);
            }
        }

        return $fields->values();
    }

    /**
     * First non-null value up the tree (section, vertical, duration, renewal price).
     */
    public function inherited(string $column): mixed
    {
        foreach ($this->lineage() as $category) {
            $value = $category->{$column};

            if ($value !== null && ! ($column === 'vertical' && $value === 'general')) {
                return $value;
            }
        }

        return $column === 'vertical' ? 'general' : null;
    }

    /**
     * IDs of this category and every descendant (for filtering a section).
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $frontier = self::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /**
     * IDs of every category whose inherited vertical is this one (G-W21).
     *
     * @return array<int, int>
     */
    public static function idsForVertical(string $vertical): array
    {
        $all = self::query()->get(['id', 'parent_id', 'vertical'])->keyBy('id');
        $resolve = function (int $id, int $depth = 0) use (&$resolve, $all): string {
            $category = $all->get($id);

            if ($category === null || $depth > 10) {
                return 'general';
            }

            if ($category->vertical !== null && $category->vertical !== 'general') {
                return $category->vertical;
            }

            return $category->parent_id === null ? 'general' : $resolve((int) $category->parent_id, $depth + 1);
        };

        return $all->keys()->filter(fn (int $id): bool => $resolve($id) === $vertical)->values()->all();
    }

    public function name(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function needsContract(): bool
    {
        foreach ($this->lineage() as $category) {
            if ($category->requires_contract) {
                return true;
            }
        }

        return false;
    }
}
