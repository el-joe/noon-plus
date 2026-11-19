<?php

namespace App\Domains\Classifieds\Http;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedAdMedia;
use App\Domains\Classifieds\Models\ClassifiedFavourite;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedRequests;
use App\Domains\RealEstate\Support\ExclusiveContracts;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Classified ad shapes for the storefront and the app (OpenSooq card:
 * image count, key attributes, city, time, verified badge; detail: all
 * attributes, map, ad ID, seller box, exclusive marketer). The phone is
 * never part of these shapes (phone reveal is logged separately).
 */
class ClassifiedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function card(ClassifiedAd $ad): array
    {
        $photos = $ad->media->where('type', 'photo')->sortBy('sort_order');

        return [
            'number' => $ad->number,
            'kind' => $ad->kind,
            'title' => $ad->title,
            'price' => MoneyResource::of($ad->price === null ? null : (string) $ad->price, $ad->currency),
            'is_negotiable' => $ad->is_negotiable,
            'category' => $ad->category?->slug,
            'city' => $ad->city,
            'area' => $ad->area,
            'image' => ($first = $photos->first()) === null ? null : self::url($first),
            'image_count' => $photos->count(),
            'has_video' => $ad->media->contains('type', 'video'),
            'key_specs' => array_slice($ad->specs ?? [], 0, 4, true),
            'is_featured' => $ad->featured_until !== null && $ad->featured_until->isFuture(),
            'verified_business' => $ad->vendor?->verified_at !== null,
            'location' => $ad->latitude === null ? null : ['lat' => (float) $ad->latitude, 'lng' => (float) $ad->longitude],
            'posted_at' => $ad->approved_at?->toIso8601String(),
            'status' => $ad->status->value,
            'property' => $ad->relationLoaded('property') && $ad->property !== null ? $ad->property->only(['purpose', 'property_type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing', 'rent_period']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ClassifiedAd $ad): array
    {
        $exclusive = $ad->exists ? app(ExclusiveContracts::class)->activeFor($ad) : null;

        return array_merge(self::card($ad), [
            'vertical' => $ad->category?->inherited('vertical') ?? 'general',
            'request_types' => app(ClassifiedRequests::class)->typesFor($ad),
            'description' => $ad->description,
            'specs' => $ad->specs ?? [],
            'media' => $ad->media->sortBy('sort_order')->values()->map(fn (ClassifiedAdMedia $media): array => ['id' => $media->id, 'type' => $media->type, 'url' => self::url($media), 'duration_seconds' => $media->duration_seconds])->all(),
            'property' => $ad->property === null ? null : $ad->property->only(['purpose', 'property_type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing', 'rent_period', 'permit_number', 'permit_authority', 'completion_on', 'developer', 'payment_plan', 'features']),
            'seller' => [
                'type' => $ad->vendor_id !== null ? 'shop' : ($ad->marketer_profile_id !== null ? 'broker' : 'individual'),
                'shop' => $ad->vendor === null ? null : ['slug' => $ad->vendor->slug, 'name' => $ad->vendor->store_name, 'verified' => $ad->vendor->verified_at !== null, 'rating' => $ad->vendor->rating === null ? null : (string) $ad->vendor->rating, 'rating_count' => (int) $ad->vendor->rating_count, 'member_since' => $ad->vendor->created_at?->toDateString()],
                'broker' => $ad->marketer === null ? null : ['slug' => $ad->marketer->slug, 'name' => $ad->marketer->display_name, 'verified' => (bool) $ad->marketer->is_verified, 'member_since' => $ad->marketer->created_at?->toDateString()],
                'member_since' => self::memberSince($ad),
                'has_phone' => true,
            ],
            'exclusive_marketer' => $exclusive === null ? null : ['slug' => $exclusive->marketer->slug, 'name' => $exclusive->marketer->display_name, 'scope' => $exclusive->subject_type],
            'views' => $ad->views,
            // QC-061: "Save (n)" on the ad title card.
            'saves' => $ad->exists ? ClassifiedFavourite::query()->where('classified_ad_id', $ad->id)->count() : 0,
            'expires_at' => $ad->expires_at?->toIso8601String(),
            'rejection_reason' => $ad->rejection_reason,
            'sold' => $ad->sold_at === null ? null : ['price' => MoneyResource::of((string) $ad->sold_price, $ad->currency), 'at' => $ad->sold_at->toIso8601String()],
        ]);
    }

    /**
     * When the advertiser joined: the shop, the broker profile, else the owner account.
     */
    private static function memberSince(ClassifiedAd $ad): ?string
    {
        $joined = $ad->vendor?->created_at ?? $ad->marketer?->created_at;

        if ($joined !== null) {
            return $joined->toDateString();
        }

        $created = User::query()->whereKey($ad->owner_user_id)->value('created_at');

        return $created === null ? null : Carbon::parse($created)->toDateString();
    }

    private static function url(ClassifiedAdMedia $media): string
    {
        return Storage::disk(ClassifiedAds::DISK)->url($media->path);
    }
}
