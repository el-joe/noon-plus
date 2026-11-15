<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Exceptions\ContractNotConfigured;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Models\Country;
use Illuminate\Support\Collection;

/**
 * ContractResolver (SRS §13.5, ADR 8). A country's own templates win; the
 * legacy/global ones (country null) serve countries without their own;
 * another country's template is never used. Only published versions count
 * (scheduled notice versions apply when the hourly job publishes them).
 *
 * Individual contracts (seller_category): product assignment (if the product
 * is not exempt) → nearest assigned category in the lineage → the country's
 * default template of the kind; nothing configured raises
 * {@see ContractNotConfigured}. Customer purchase contracts use the same
 * lookup but are simply not required when none is assigned.
 */
class ContractResolver
{
    /**
     * Current version of a kind that has one template per country (Seller
     * Agreement, marketer onboarding, travel agency, broker, shipping).
     */
    public function current(ContractKind $kind, Country $country): ?ContractVersion
    {
        foreach ([$country->id, null] as $countryId) {
            $version = $this->templates($kind, $countryId)
                ->sortByDesc(fn (ContractTemplate $template): array => [(int) $template->is_default, $template->id])
                ->map(fn (ContractTemplate $template): ?ContractVersion => $template->publishedVersion)
                ->filter()
                ->first();

            if ($version !== null) {
                return $version;
            }

            if ($this->templates($kind, $countryId)->isNotEmpty()) {
                return null;
            }
        }

        return null;
    }

    /**
     * Individual contract for a category (and optionally a product).
     *
     * @throws ContractNotConfigured for seller_category when nothing is configured
     */
    public function forCategory(ContractKind $kind, Country $country, Category $category, ?Product $product = null): ?ResolvedContract
    {
        if ($product !== null && $product->contract_requirement !== 'exempt') {
            $version = $this->assigned($kind, $country, ContractAssignment::SUBJECT_PRODUCT, (string) $product->id);

            if ($version !== null) {
                return new ResolvedContract($version, ContractAssignment::SUBJECT_PRODUCT, $product->id);
            }
        }

        foreach ($category->lineage() as $ancestor) {
            $version = $this->assigned($kind, $country, ContractAssignment::SUBJECT_CATEGORY, (string) $ancestor->id);

            if ($version !== null) {
                return new ResolvedContract($version, ContractAssignment::SUBJECT_CATEGORY, $ancestor->id);
            }
        }

        $default = $this->templates($kind, $country->id)->where('is_default', true)->map->publishedVersion->filter()->first();

        if ($default !== null) {
            return new ResolvedContract($default, 'default', 0);
        }

        if ($kind === ContractKind::SellerCategory) {
            throw new ContractNotConfigured(__('No contract is configured for this category in :country yet.', ['country' => $country->name_en]));
        }

        return null;
    }

    /**
     * Classified contracts (WP6, FR-CLS-3): the nearest classified category
     * in the lineage with an assignment of the kind, then the country's
     * default template of the kind. A seller contract required by the
     * category with nothing configured raises {@see ContractNotConfigured};
     * customer purchase contracts are simply not required.
     *
     * @throws ContractNotConfigured
     */
    public function forClassifiedCategory(ContractKind $kind, Country $country, ClassifiedCategory $category): ?ResolvedContract
    {
        foreach ($category->lineage() as $ancestor) {
            $version = $this->assigned($kind, $country, ContractAssignment::SUBJECT_CLASSIFIED_CATEGORY, (string) $ancestor->id);

            if ($version !== null) {
                return new ResolvedContract($version, ContractAssignment::SUBJECT_CLASSIFIED_CATEGORY, $ancestor->id);
            }
        }

        if ($kind === ContractKind::CustomerPurchase) {
            return null;
        }

        $default = $this->templates($kind, $country->id)->where('is_default', true)->map->publishedVersion->filter()->first();

        if ($default !== null) {
            return new ResolvedContract($default, 'default', 0);
        }

        throw new ContractNotConfigured(__('No contract is configured for this classified category in :country yet.', ['country' => $country->name_en]));
    }

    /**
     * Collaboration contracts (seller_marketer) that apply to the marketer
     * job types in the seller's country: the country's own, else legacy;
     * a template with destinations applies only to those job types.
     *
     * @param  array<int, string>  $jobTypes
     * @return Collection<int, ContractVersion>
     */
    public function collaboration(Country $country, array $jobTypes): Collection
    {
        foreach ([$country->id, null] as $countryId) {
            $templates = $this->templates(ContractKind::SellerMarketer, $countryId);

            if ($templates->isNotEmpty()) {
                return $templates
                    ->filter(fn (ContractTemplate $template): bool => empty($template->destinations) || array_intersect($template->destinations, $jobTypes) !== [])
                    ->map(fn (ContractTemplate $template): ?ContractVersion => $template->publishedVersion)
                    ->filter()
                    ->values();
            }
        }

        return collect();
    }

    /**
     * Marketer job types without any collaboration contract in the country
     * (`uncoveredMarketerDestinations`, so a gap is visible to the admin).
     *
     * @return array<int, string>
     */
    public function uncoveredMarketerDestinations(Country $country): array
    {
        return array_values(array_filter(['influencer', 'affiliate', 'broker'], fn (string $type): bool => $this->collaboration($country, [$type])->isEmpty()));
    }

    /**
     * @return Collection<int, ContractTemplate>
     */
    private function templates(ContractKind $kind, ?int $countryId): Collection
    {
        return ContractTemplate::query()
            ->where('kind', $kind)
            ->when($countryId === null, fn ($query) => $query->whereNull('country_id'), fn ($query) => $query->where('country_id', $countryId))
            ->with('publishedVersion')
            ->get();
    }

    private function assigned(ContractKind $kind, Country $country, string $subjectType, string $subjectKey): ?ContractVersion
    {
        return ContractAssignment::query()
            ->where('country_id', $country->id)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->whereHas('template', fn ($query) => $query->where('kind', $kind)->where(fn ($inner) => $inner->whereNull('country_id')->orWhere('country_id', $country->id)))
            ->with('template.publishedVersion')
            ->get()
            ->sortByDesc(fn (ContractAssignment $assignment): ?int => $assignment->template->country_id)
            ->map(fn (ContractAssignment $assignment): ?ContractVersion => $assignment->template->publishedVersion)
            ->filter()
            ->first();
    }
}
