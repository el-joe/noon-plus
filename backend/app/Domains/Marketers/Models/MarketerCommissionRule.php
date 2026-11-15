<?php

namespace App\Domains\Marketers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A commission rule (FR-MKT-2): per marketer (profile rule) or platform
 * default (`marketer_profile_id` null = Marketer Settings grid), per
 * vertical and optionally category. Null fields fall through to the next
 * rule in the resolution order.
 */
#[Fillable(['marketer_profile_id', 'vertical', 'category_id', 'influencer_fixed', 'affiliate_percent', 'broker_fixed', 'broker_percent', 'fee_percent', 'fee_fixed'])]
class MarketerCommissionRule extends Model
{
    use UsesCountryDatabase;

    public const FIELDS = ['influencer_fixed', 'affiliate_percent', 'broker_fixed', 'broker_percent', 'fee_percent', 'fee_fixed'];
}
