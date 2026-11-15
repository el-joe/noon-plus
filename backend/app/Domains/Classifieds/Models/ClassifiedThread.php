<?php

namespace App\Domains\Classifieds\Models;

use App\Domains\Classifieds\Enums\ThreadStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inquiry / chat thread between a buyer (customer or guest) and the ad owner (FR-CLS-4).
 */
#[Fillable(['classified_ad_id', 'customer_user_id', 'seller_user_id', 'contact_name', 'contact_email', 'contact_phone', 'status', 'marketer_profile_id', 'referral_code', 'last_message_at'])]
class ClassifiedThread extends Model
{
    use UsesCountryDatabase;

    protected $table = 'classified_threads';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ThreadStatus::class,
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedAd, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(ClassifiedAd::class, 'classified_ad_id');
    }

    /**
     * @return HasMany<ClassifiedMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ClassifiedMessage::class);
    }
}
