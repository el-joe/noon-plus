<?php

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sub_order_id', 'from_status', 'to_status', 'actor_type', 'actor_user_id', 'reason'])]
class OrderStatusChange extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => SubOrderStatus::class,
            'to_status' => SubOrderStatus::class,
            'actor_type' => ActorType::class,
        ];
    }
}
