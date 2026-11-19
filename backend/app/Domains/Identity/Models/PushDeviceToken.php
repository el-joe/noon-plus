<?php

namespace App\Domains\Identity\Models;

use App\Models\User;
use App\Shared\Notifications\Push\SendPushNotification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push registration token of one signed-in device (G-A2). Every in-app
 * notification is pushed to the user's tokens of that app
 * ({@see SendPushNotification}; tokens the
 * provider reports gone are deleted). A token belongs to one account at a
 * time (re-registering moves it), and is dropped when that device signs out.
 */
#[Fillable(['user_id', 'personal_access_token_id', 'token', 'token_hash', 'platform', 'app', 'country_code', 'locale', 'last_seen_at'])]
#[Hidden(['token', 'token_hash'])]
class PushDeviceToken extends Model
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    public const APPS = ['customer', 'delivery', 'vendor', 'marketer', 'supervisor', 'travel_agency'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
