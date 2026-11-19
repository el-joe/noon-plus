<?php

namespace App\Domains\RealEstate\Http;

use App\Domains\Classifieds\Http\ClassifiedPresenter;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\RealEstate\Models\PropertyViewing;

/**
 * API shape of a viewing / reserve request (owner decision D12: a record
 * only, so there is no status). The customer's own list shows the ad, the
 * dates and the message; the advertiser's list adds the contact details.
 */
class ViewingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function record(PropertyViewing $viewing, bool $withContact = false): array
    {
        $timezone = app(CountryContext::class)->current()->timezone;

        return [
            'id' => $viewing->id,
            'type' => $viewing->type,
            'preferred_at' => $viewing->preferred_at->setTimezone($timezone)->toIso8601String(),
            'note' => $viewing->note,
            'created_at' => $viewing->created_at?->toIso8601String(),
            'ad' => $viewing->ad === null ? null : array_intersect_key(ClassifiedPresenter::card($viewing->ad), array_flip(['number', 'title', 'price', 'image', 'city', 'area', 'status', 'category'])),
        ] + ($withContact ? ['contact' => ['name' => $viewing->contact_name, 'email' => $viewing->contact_email, 'phone' => $viewing->contact_phone]] : []);
    }
}
