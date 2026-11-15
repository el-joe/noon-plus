<?php

namespace App\Domains\Finance\Models;

use App\Domains\Orders\Models\SubOrder;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash collected for one delivered COD sub-order (SRS §8). `agent_user_id`
 * is filled by the delivery assignment (WP7); until then it is null.
 */
#[Fillable(['sub_order_id', 'order_id', 'agent_user_id', 'currency', 'amount', 'cod_fee', 'collected_at', 'cod_settlement_id'])]
class CodCollection extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['collected_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }
}
