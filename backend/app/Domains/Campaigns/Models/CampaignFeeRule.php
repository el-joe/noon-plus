<?php

namespace App\Domains\Campaigns\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketing Fees grid (SRS §20.3): `fixed` (one row per country: amount +
 * % of base), `variable` (category × fulfilment fbm/fbn/fbp, falling back
 * to the category's parents then a row without category), `vertical`
 * (classified / travel).
 */
#[Fillable(['kind', 'category_id', 'fulfilment', 'vertical', 'amount', 'percent'])]
class CampaignFeeRule extends Model
{
    use UsesCountryDatabase;

    public const KIND_FIXED = 'fixed';

    public const KIND_VARIABLE = 'variable';

    public const KIND_VERTICAL = 'vertical';
}
