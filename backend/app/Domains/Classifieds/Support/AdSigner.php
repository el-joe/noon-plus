<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Contracts\Enums\SignerType;

/**
 * Who signs the seller contract of an ad: the business shop (vendor), the
 * broker (marketer profile) or the individual owner (customer user).
 */
final readonly class AdSigner
{
    public function __construct(public SignerType $type, public int $id) {}

    public static function of(ClassifiedAd $ad): self
    {
        return match (true) {
            $ad->vendor_id !== null => new self(SignerType::Vendor, (int) $ad->vendor_id),
            $ad->marketer_profile_id !== null => new self(SignerType::Marketer, (int) $ad->marketer_profile_id),
            default => new self(SignerType::Customer, (int) $ad->owner_user_id),
        };
    }
}
