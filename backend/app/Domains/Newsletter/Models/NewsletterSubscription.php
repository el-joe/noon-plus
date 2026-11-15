<?php

namespace App\Domains\Newsletter\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A newsletter subscription in one country (G-W12):
 * pending → confirmed (double opt-in link) → unsubscribed; subscribing
 * again after unsubscribing starts a new confirmation.
 */
#[Fillable(['email', 'locale', 'status', 'token_hash', 'source', 'confirmation_sent_at', 'confirmed_at', 'unsubscribed_at'])]
#[Hidden(['token_hash'])]
class NewsletterSubscription extends Model
{
    use UsesCountryDatabase;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const UNSUBSCRIBED = 'unsubscribed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmation_sent_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
        ];
    }
}
