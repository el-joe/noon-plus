<?php

namespace App\Domains\Disputes\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One message in a dispute thread (customer, seller or admin).
 */
#[Fillable(['dispute_id', 'author_type', 'author_user_id', 'body'])]
class DisputeMessage extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['author_type' => ActorType::class, 'created_at' => 'immutable_datetime'];
    }
}
