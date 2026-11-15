<?php

namespace App\Domains\GiftCards\Support;

/**
 * Gift card and voucher codes: 16 characters without look-alikes, shown as
 * XXXX-XXXX-XXXX-XXXX, looked up by a keyed hash (HMAC with the app key) so
 * the database never holds a usable plain code.
 */
class GiftCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        $characters = '';

        for ($index = 0; $index < 16; $index++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }

    public function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), (string) config('app.key'));
    }
}
