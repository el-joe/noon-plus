<?php

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time 6-digit sign-in code sent by email (MASTER_PLAN ADR 5).
 */
#[Fillable(['email', 'purpose', 'code_hash', 'attempts', 'expires_at', 'locked_until', 'consumed_at'])]
#[Hidden(['code_hash'])]
class EmailLoginCode extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
