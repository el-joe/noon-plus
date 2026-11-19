<?php

namespace App\Domains\Contracts\Enums;

use App\Shared\StateMachine\StringStatusMachine;

/**
 * A signature (lifecycle catalogue §8.2): active until a newer version is
 * signed or published (superseded) or it is revoked. The column stays a
 * plain string (`ContractSignature::STATUS_*`); moves go through
 * {@see StringStatusMachine} with a history row (QC-053).
 */
enum ContractSignatureStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Revoked = 'revoked';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Superseded, self::Revoked],
            self::Superseded, self::Revoked => [],
        };
    }
}
