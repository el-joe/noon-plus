<?php

namespace App\Shared\Notifications\Email\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An address we must not email (central). Topic `*` covers every topic:
 * for a hard bounce or a manual block even transactional mail stops; for a
 * complaint or an unsubscribe only mail that can be turned off stops. A
 * named topic (e.g. `reminders`) stops just that topic — used for guests,
 * who have no preferences row.
 */
#[Fillable(['email_hash', 'email_masked', 'topic', 'reason', 'source', 'detail', 'created_by'])]
class EmailSuppression extends Model
{
    public const REASONS = ['hard_bounce', 'complaint', 'unsubscribed', 'manual'];

    /**
     * Reasons that stop even transactional and security mail.
     */
    public const BLOCKS_ALL = ['hard_bounce', 'manual'];
}
