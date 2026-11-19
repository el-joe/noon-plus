<?php

namespace App\Domains\Onboarding\Models;

use App\Domains\Documents\Models\Document;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner application of one join section in one country (portal
 * wizards, MASTER_PLAN §5a). Guests reach it with the access token returned
 * at creation (only its SHA-256 is stored); the chosen password is kept as
 * a hash until approval creates the account. Documents are stored with
 * owner `application` and move to the tenant on approval.
 *
 * @property ApplicationType $type
 * @property ApplicationStatus $status
 */
#[Fillable([
    'number', 'type', 'status', 'token_hash', 'resume_token_hashes', 'email', 'email_verified_at', 'name', 'phone', 'password_hash', 'locale', 'store_slug', 'data',
    'completed_steps', 'logo_path', 'assigned_to_user_id', 'acquired_by_user_id', 'referral_code', 'reviewed_by_user_id', 'decision_reason', 'requested_changes',
    'user_id', 'tenant_type', 'tenant_id', 'submitted_at', 'decided_at',
])]
#[Hidden(['token_hash', 'resume_token_hashes', 'password_hash', 'email_code_hash'])]
class PartnerApplication extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const DOCUMENT_OWNER = 'application';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApplicationType::class,
            'status' => ApplicationStatus::class,
            'data' => 'array',
            'completed_steps' => 'array',
            'requested_changes' => 'array',
            'resume_token_hashes' => 'array',
            'submitted_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'email_code_expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_id')->where('owner_type', self::DOCUMENT_OWNER);
    }

    /**
     * @return array<string, mixed>
     */
    public function step(string $step): array
    {
        return (array) ($this->data[$step] ?? []);
    }

    public function hasCompleted(string $step): bool
    {
        return in_array($step, $this->completed_steps ?? [], true);
    }

    /**
     * The wizard's own token, or one of the tokens from a resume link (RT-006).
     */
    public function matchesToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $hash = hash('sha256', $token);

        foreach ([$this->token_hash, ...($this->resume_token_hashes ?? [])] as $known) {
            if (is_string($known) && hash_equals($known, $hash)) {
                return true;
            }
        }

        return false;
    }
}
