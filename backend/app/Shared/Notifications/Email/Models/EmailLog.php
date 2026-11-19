<?php

namespace App\Shared\Notifications\Email\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One catalogue email to one recipient (central). The address is kept
 * hashed (search), masked (display) and encrypted (resend); the payload
 * (variables and options) is encrypted and left empty for sensitive events
 * such as sign-in codes, which cannot be resent.
 *
 * Status: queued → sent | failed | skipped (template off) | suppressed
 * (preference / suppression list); later bounced | complained (webhook).
 */
#[Fillable(['event', 'category', 'actor', 'user_id', 'recipient_hash', 'recipient_masked', 'recipient_encrypted', 'locale', 'country_code', 'entity_type', 'entity_id', 'idempotency_key', 'template_version', 'subject', 'status', 'provider_message_id', 'failure_reason', 'attempts', 'payload', 'resent_from_id', 'queued_at', 'sent_at', 'failed_at', 'bounced_at', 'opened_at'])]
#[Hidden(['recipient_encrypted', 'payload'])]
class EmailLog extends Model
{
    public const STATUSES = ['queued', 'sent', 'failed', 'skipped', 'suppressed', 'bounced', 'complained'];

    protected $table = 'email_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_encrypted' => 'encrypted',
            'payload' => 'encrypted:array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'bounced_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function recipient(): string
    {
        return (string) $this->recipient_encrypted;
    }

    public function canResend(): bool
    {
        return $this->payload !== null && ! in_array($this->status, ['queued'], true);
    }
}
