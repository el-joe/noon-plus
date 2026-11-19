<?php

namespace App\Domains\RealEstate\Models;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A viewing or reserve request (`type`) on a classified ad: the customer's
 * contact details, preferred time and message (owner decision D8: no
 * payment, guests allowed). Owner decision D12: a record only, with no
 * lifecycle. The legacy `status` and `scheduled_at` columns are frozen:
 * nothing writes them (new rows keep the `requested` default), nothing
 * reads them, and they are hidden from serialisation. Earlier rows keep
 * their values, so no data is lost.
 */
#[Fillable(['classified_ad_id', 'type', 'customer_user_id', 'contact_name', 'contact_email', 'contact_phone', 'marketer_profile_id', 'owner_user_id', 'preferred_at', 'note'])]
#[Hidden(['status', 'scheduled_at'])]
class PropertyViewing extends Model
{
    use UsesCountryDatabase;

    protected $table = 'property_viewings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_at' => 'immutable_datetime',
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
