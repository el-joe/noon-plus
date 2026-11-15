<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Shared\Settings\VerticalSettings;

/**
 * Which D8 requests (no payment — contact details and a preferred time) a
 * live classified ad takes (G-W22): `viewing` (see the item / property) and
 * `reserve` (ask the seller to hold it). Allowed per vertical with the
 * setting `classified.request_types` ({vertical: [types]}, country override
 * wins; default in `config('nawy.classified.request_types')`). A vertical
 * missing from the map falls back to `general`.
 */
class ClassifiedRequests
{
    public const TYPES = ['viewing', 'reserve'];

    public function __construct(private VerticalSettings $settings) {}

    /**
     * @return array<int, string>
     */
    public function typesForVertical(string $vertical): array
    {
        $map = (array) $this->settings->get('classified.request_types');
        $types = $map[$vertical] ?? $map['general'] ?? [];

        return array_values(array_intersect(self::TYPES, (array) $types));
    }

    /**
     * @return array<int, string>
     */
    public function typesFor(ClassifiedAd $ad): array
    {
        if ($ad->status !== ClassifiedAdStatus::Active || $ad->category === null) {
            return [];
        }

        return $this->typesForVertical((string) $ad->category->inherited('vertical'));
    }

    public function allows(ClassifiedAd $ad, string $type): bool
    {
        return in_array($type, $this->typesFor($ad), true);
    }
}
