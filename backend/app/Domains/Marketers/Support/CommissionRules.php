<?php

namespace App\Domains\Marketers\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Marketers\Models\MarketerCommissionRule;
use App\Domains\Marketers\Models\MarketerProfile;
use Illuminate\Support\Collection;

/**
 * Resolves commission fields (FR-MKT-2, SRS §11.10): each field takes the
 * first non-null value from the marketer's profile rule for the category
 * (then its parents), the profile rule for the vertical, the platform
 * default for the category (then parents), the platform vertical default.
 */
class CommissionRules
{
    /**
     * @return array<string, string|null>
     */
    public function resolve(?MarketerProfile $marketer, ?Category $category, string $vertical = 'product', bool $profileOnly = false): array
    {
        $chain = collect();
        $owners = $profileOnly ? [$marketer?->id] : [$marketer?->id, null];

        foreach ($owners as $ownerIndex => $marketerId) {
            if ($ownerIndex === 0 && $marketer === null) {
                continue;
            }

            $rules = $this->rulesFor($marketerId, $vertical);

            foreach ($category?->lineage() ?? [] as $ancestor) {
                $chain->push($rules->firstWhere('category_id', $ancestor->id));
            }

            $chain->push($rules->firstWhere('category_id', null));
        }

        $resolved = [];

        foreach (MarketerCommissionRule::FIELDS as $field) {
            $resolved[$field] = $chain->filter()->map(fn (MarketerCommissionRule $rule) => $rule->{$field})->first(fn ($value): bool => $value !== null);
            $resolved[$field] = $resolved[$field] === null ? null : (string) $resolved[$field];
        }

        return $resolved;
    }

    /**
     * Set (or clear) one rule row.
     *
     * @param  array<string, string|null>  $values
     */
    public function save(?int $marketerId, ?int $categoryId, array $values, string $vertical = 'product'): MarketerCommissionRule
    {
        $rule = MarketerCommissionRule::query()
            ->where('marketer_profile_id', $marketerId)->where('vertical', $vertical)->where('category_id', $categoryId)
            ->firstOrNew(['marketer_profile_id' => $marketerId, 'vertical' => $vertical, 'category_id' => $categoryId]);

        foreach (MarketerCommissionRule::FIELDS as $field) {
            if (array_key_exists($field, $values)) {
                $rule->{$field} = $values[$field] === '' ? null : $values[$field];
            }
        }

        $rule->save();

        return $rule;
    }

    /**
     * @return Collection<int, MarketerCommissionRule>
     */
    private function rulesFor(?int $marketerId, string $vertical): Collection
    {
        return MarketerCommissionRule::query()->where('marketer_profile_id', $marketerId)->where('vertical', $vertical)->get();
    }
}
