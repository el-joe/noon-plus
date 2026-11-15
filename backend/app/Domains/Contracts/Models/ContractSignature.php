<?php

namespace App\Domains\Contracts\Models;

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature with its evidence (SRS §13.6), country database: typed name,
 * time, IP, user agent, version, locale, method, frozen copy on the private
 * disk and its SHA-256. Never edited or deleted — only superseded/revoked.
 */
#[Fillable([
    'contract_template_id', 'contract_version_id', 'version', 'kind', 'signer_type', 'signer_id', 'signer_user_id',
    'subject_type', 'subject_id', 'purpose', 'full_name', 'locale', 'method', 'ip_address', 'user_agent',
    'frozen_path', 'sha256', 'status', 'open_call', 'signed_at', 'superseded_at',
])]
class ContractSignature extends Model
{
    use UsesCountryDatabase;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_REVOKED = 'revoked';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContractKind::class,
            'signer_type' => SignerType::class,
            'method' => SignatureMethod::class,
            'open_call' => 'boolean',
            'signed_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
