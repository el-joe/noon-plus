<?php

namespace App\Shared\Localization;

use BackedEnum;

/**
 * A translatable label for an enum value or other machine value
 * (QC-028): `pending_review` → __('Pending review'). Every backed enum value
 * has an entry in `lang/ar.json` (checked by QaWave3ArabicTest).
 */
final class Label
{
    /**
     * Values whose humanised key does not read well (RT-010).
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        'returns_refunds' => 'Returns & refunds',
    ];

    public static function key(BackedEnum|string|null $value): string
    {
        $value = $value instanceof BackedEnum ? (string) $value->value : (string) $value;

        return self::OVERRIDES[$value] ?? ucfirst(str_replace(['_', ':'], ' ', $value));
    }

    public static function of(BackedEnum|string|null $value): string
    {
        $key = self::key($value);

        return $key === '' ? '' : __($key);
    }
}
