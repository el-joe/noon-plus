<?php

namespace App\Domains\Contracts\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a template lineage to a subject in one country (category, product,
 * marketer or job type). It follows the template's newest published version.
 */
#[Fillable(['contract_template_id', 'country_id', 'subject_type', 'subject_key'])]
class ContractAssignment extends Model
{
    public const SUBJECT_CATEGORY = 'category';

    public const SUBJECT_PRODUCT = 'product';

    /** A classified category (WP6): seller and customer purchase contracts of classified ads. */
    public const SUBJECT_CLASSIFIED_CATEGORY = 'cls_category';

    /**
     * @return BelongsTo<ContractTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }
}
