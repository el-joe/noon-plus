<?php

namespace App\Domains\FlashSales\Models;

use App\Domains\FlashSales\Enums\FlashSaleSubmissionStatus;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * History row for a flash-sale submission status change, with the actor.
 */
#[Fillable(['flash_sale_submission_id', 'from_status', 'to_status', 'actor_type', 'actor_user_id', 'reason'])]
class FlashSaleSubmissionStatusChange extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'flash_sale_submission_status_history';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => FlashSaleSubmissionStatus::class,
            'to_status' => FlashSaleSubmissionStatus::class,
            'actor_type' => ActorType::class,
        ];
    }
}
