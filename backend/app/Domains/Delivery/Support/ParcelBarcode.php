<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;

/**
 * What the parcel label barcode encodes (docs/project/storefront-api-additions.md
 * § "Parcel label barcode"): Code 39 of `<shipment number>.<C>`, where the
 * shipment (AWB) number is `SH-<sub-order number>` and C is the Code 39
 * mod-43 check character of the shipment number. The delivery app checks
 * the character before it accepts a scan and the API checks it again
 * (`POST delivery/assignments/{number}/scan`). A number typed by hand has
 * no check character and is compared as is.
 */
final class ParcelBarcode
{
    public const CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    public static function checkChar(string $value): ?string
    {
        $sum = 0;

        foreach (str_split($value) as $character) {
            $position = strpos(self::CHARSET, $character);

            if ($position === false) {
                return null;
            }

            $sum += $position;
        }

        return self::CHARSET[$sum % 43];
    }

    public static function payload(Shipment|string $shipment): string
    {
        $number = self::normalise($shipment instanceof Shipment ? (string) $shipment->number : $shipment);

        return $number.'.'.self::checkChar($number);
    }

    /**
     * Upper case, no spaces, no Code 39 start/stop `*`.
     */
    public static function normalise(string $code): string
    {
        return trim(strtoupper((string) preg_replace('/\s+/', '', trim($code))), '*');
    }

    /**
     * @return array{base: string, labelled: bool, valid: bool}
     */
    public static function parse(string $raw): array
    {
        $code = self::normalise($raw);

        if (! str_contains($code, '.')) {
            return ['base' => $code, 'labelled' => false, 'valid' => true];
        }

        if (str_ends_with($code, '.')) {
            $base = substr($code, 0, -1);

            // `base..` (check character `.`), or `base.` + a space the scanner trimmed.
            if (str_ends_with($base, '.') && strlen($base) > 1 && self::checkChar(substr($base, 0, -1)) === '.') {
                return ['base' => substr($base, 0, -1), 'labelled' => true, 'valid' => true];
            }

            return ['base' => $base, 'labelled' => true, 'valid' => $base !== '' && self::checkChar($base) === ' '];
        }

        $dot = strrpos($code, '.');
        $base = substr($code, 0, $dot);
        $check = substr($code, $dot + 1);

        return ['base' => $base, 'labelled' => true, 'valid' => $base !== '' && strlen($check) === 1 && self::checkChar($base) === $check];
    }

    /**
     * match | bad_label | other_parcel | no_match
     */
    public static function match(DeliveryAssignment $assignment, string $code): string
    {
        $parsed = self::parse($code);
        $shipment = self::normalise((string) $assignment->shipment?->number);

        if ($parsed['base'] === '') {
            return 'no_match';
        }

        if ($parsed['labelled']) {
            if (! $parsed['valid']) {
                return 'bad_label';
            }

            return $parsed['base'] === $shipment ? 'match' : 'other_parcel';
        }

        $numbers = array_map(self::normalise(...), array_filter([$shipment, $assignment->number, $assignment->subOrder?->number, $assignment->subOrder?->order?->number]));

        return in_array($parsed['base'], $numbers, true) ? 'match' : 'no_match';
    }
}
