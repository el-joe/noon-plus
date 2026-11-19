<?php

namespace App\Shared\Notifications\Email;

/**
 * How an address is stored outside the users table: a SHA-256 hash for
 * lookups (log filter, suppression list) and a masked form for display.
 */
final class EmailAddress
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalize($email));
    }

    /**
     * `sara.ali@example.com` → `s******@e******.com`.
     */
    public static function mask(string $email): string
    {
        $email = self::normalize($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $dot = strrpos($domain, '.');
        $host = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        $hide = fn (string $part): string => $part === '' ? '' : mb_substr($part, 0, 1).str_repeat('*', max(1, min(6, mb_strlen($part) - 1)));

        return mb_substr($hide($local).'@'.$hide($host).$tld, 0, 120);
    }
}
