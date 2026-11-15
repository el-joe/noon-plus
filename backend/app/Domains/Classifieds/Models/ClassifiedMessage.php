<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One message of an inquiry thread.
 */
#[Fillable(['classified_thread_id', 'sender_user_id', 'sender_role', 'body', 'read_at'])]
class ClassifiedMessage extends Model
{
    use UsesCountryDatabase;

    protected $table = 'classified_messages';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
        ];
    }
}
