<?php

namespace App\Domains\Classifieds\Models;

use App\Domains\Classifieds\Enums\ReportStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An abuse report on an ad (admin abuse queue).
 */
#[Fillable(['classified_ad_id', 'reporter_user_id', 'reason', 'details', 'status', 'resolution_note', 'handled_by_user_id'])]
class ClassifiedReport extends Model
{
    use UsesCountryDatabase;

    protected $table = 'classified_reports';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
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
