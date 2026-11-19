<?php

namespace App\Shared\Notifications\Email\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's choice for one email topic: a category (`reminders`,
 * `cart_reminders`, …) or one admin alert (`admin:{event}`). No row means
 * the category default.
 */
#[Fillable(['user_id', 'topic', 'enabled'])]
class EmailPreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
