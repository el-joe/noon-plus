<?php

namespace App\Domains\Contracts\Enums;

/**
 * Template version lifecycle (lifecycle catalogue §8.1): draft → published
 * (click-wrap) or scheduled (notice, effective date ahead) → published;
 * published → retired when a newer version is published.
 */
enum ContractVersionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Retired = 'retired';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Scheduled],
            self::Scheduled => [self::Published, self::Draft],
            self::Published => [self::Retired],
            self::Retired => [],
        };
    }
}
