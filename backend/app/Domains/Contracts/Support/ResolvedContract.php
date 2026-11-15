<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Contracts\Models\ContractVersion;

/**
 * The version a signer must hold and the subject the signature covers
 * (category or product for individual contracts, none otherwise).
 */
final readonly class ResolvedContract
{
    public function __construct(
        public ContractVersion $version,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
    ) {}
}
