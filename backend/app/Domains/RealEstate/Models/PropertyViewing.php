<?php

namespace App\Domains\RealEstate\Models;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\RealEstate\Enums\ViewingStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A viewing or reserve request (`type`) on a classified ad: the customer's contact details and a preferred time (owner decision WP6: no payment, guests allowed).
 */
#[Fillable(['classified_ad_id', 'type', 'customer_user_id', 'contact_name', 'contact_email', 'contact_phone', 'marketer_profile_id', 'owner_user_id', 'preferred_at', 'scheduled_at', 'note'])]
class PropertyViewing extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'property_viewings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ViewingStatus::class,
            'preferred_at' => 'immutable_datetime',
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedAd, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(ClassifiedAd::class, 'classified_ad_id');
    }
}
