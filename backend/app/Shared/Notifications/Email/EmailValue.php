<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Countries\Support\CountryContext;
use App\Shared\Money\MoneyFormatter;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Email variables that must be written in the recipient's language or the
 * country's time zone, resolved when the email is rendered (not when the
 * event happens, which may be in another language):
 *
 *  - `EmailValue::t('the seller')` — a translation key (lang/ar.json)
 *  - `EmailValue::date($at)` / `EmailValue::date($at, time: true)` — local date (time)
 *  - `EmailValue::money('12.5', 'OMR')` — "OMR 12.500" (3-decimal currencies kept)
 *
 * Plain strings are used as they are.
 */
final class EmailValue
{
    /**
     * @param  array<string, scalar|null>  $replace
     * @return array{t: string, r: array<string, scalar|null>}
     */
    public static function t(string $key, array $replace = []): array
    {
        return ['t' => $key, 'r' => $replace];
    }

    /**
     * @return array{date: string, time: bool}|null
     */
    public static function date(DateTimeInterface|string|null $at, bool $time = false): ?array
    {
        return $at === null ? null : ['date' => Carbon::parse($at)->toIso8601String(), 'time' => $time];
    }

    public static function money(string|int|float|null $amount, string $currency): string
    {
        return app(MoneyFormatter::class)->format($amount === null ? '0' : (string) $amount, $currency);
    }

    /**
     * Resolve one variable in the current (recipient) locale and country.
     */
    public static function resolve(mixed $value): string
    {
        if (is_array($value) && isset($value['t'])) {
            $replace = array_map(fn ($item): string => self::resolve($item), (array) ($value['r'] ?? []));

            return (string) __((string) $value['t'], $replace);
        }

        if (is_array($value) && isset($value['date'])) {
            $context = app(CountryContext::class);
            $zone = $context->has() ? ($context->current()->timezone ?: config('app.timezone')) : config('nawy.admin_timezone', config('app.timezone'));
            $date = Carbon::parse((string) $value['date'])->setTimezone($zone)->locale(app()->getLocale());

            return $date->translatedFormat(! empty($value['time']) ? 'j F Y, H:i' : 'j F Y');
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item): string => self::resolve($item), $value));
        }

        return (string) ($value ?? '');
    }
}
