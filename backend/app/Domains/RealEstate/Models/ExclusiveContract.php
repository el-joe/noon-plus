<?php

namespace App\Domains\RealEstate\Models;

use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\RealEstate\Enums\ExclusiveStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exclusivity of a marketer on an ad or a whole classified category for a date window, with a private contract file.
 */
#[Fillable(['marketer_profile_id', 'subject_type', 'subject_id', 'starts_at', 'ends_at', 'file_path', 'revoke_reason', 'granted_by_user_id'])]
class ExclusiveContract extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const SUBJECT_AD = 'ad';

    public const SUBJECT_CATEGORY = 'category';

    protected $table = 'exclusive_contracts';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExclusiveStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }
}
