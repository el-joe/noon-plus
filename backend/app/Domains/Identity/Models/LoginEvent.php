<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in attempt (login history shown in Security & devices).
 */
#[Fillable(['user_id', 'email', 'channel', 'method', 'outcome', 'ip_address', 'user_agent'])]
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => LoginOutcome::class,
            'method' => LoginMethod::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
