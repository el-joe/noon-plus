<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Models\StaticPage;
use App\Domains\Countries\Models\Country;

/**
 * Static pages (SRS §15): the country's page, else the global one, for the
 * audience asking (storefront or portal; `both` serves either).
 */
class StaticPages
{
    public function resolve(string $slug, Country $country, string $audience): ?StaticPage
    {
        return StaticPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->whereIn('audience', [$audience, 'both'])
            ->where(fn ($query) => $query->where('country_id', $country->id)->orWhereNull('country_id'))
            ->orderByRaw('country_id is null')
            ->first();
    }
}
