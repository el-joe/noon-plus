<?php

namespace App\Domains\Migration\Support;

/**
 * Masks personal data in a legacy row shown in Admin → Data migration
 * (quarantine payloads, legacy records). Fields whose name suggests personal
 * data are masked by default; emails and IBANs are masked anywhere in free
 * text. "Reveal" shows the plain row, except secrets (password
 * hashes, tokens), which are never shown.
 */
class PiiMask
{
    private const SECRET = '/(password|secret|token|api_key|remember|two_factor|otp|cvv|pin_code)/i';

    private const PERSONAL = '/(e-?mail|phone|mobile|whatsapp|(^|_)name($|_)|signer|address|street|building|apartment|landmark|postal|zip|ip_address|user_agent|iban|account_number|swift|card|national_id|emirates_id|passport|civil_id|tax_number|dob|birth|latitude|longitude|(^|_)lat($|_)|(^|_)lng($|_)|signature|contact)/i';

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function row(array $row, bool $reveal = false): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[$key] = self::value((string) $key, $value, $reveal);
        }

        return $out;
    }

    public static function isSecret(string $key): bool
    {
        return preg_match(self::SECRET, $key) === 1;
    }

    private static function value(string $key, mixed $value, bool $reveal): mixed
    {
        if ($value === null || $value === '' || self::isId($key)) {
            return $value;
        }

        if (self::isSecret($key)) {
            return '[hidden]';
        }

        if (is_array($value)) {
            return self::row($value, $reveal);
        }

        if ($reveal) {
            return $value;
        }

        if (preg_match(self::PERSONAL, $key) === 1) {
            $text = (string) $value;

            return mb_substr($text, 0, 1).str_repeat('•', max(3, min(12, mb_strlen($text) - 1)));
        }

        if (! is_string($value)) {
            return $value;
        }

        // Free text: emails and IBANs anywhere (phone-like digits are left alone: amounts and dates look the same).
        $value = preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', fn (array $match): string => mb_substr($match[0], 0, 2).'***@***', $value) ?? $value;

        return preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '[IBAN]', $value) ?? $value;
    }

    private static function isId(string $key): bool
    {
        return $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_uuid') || str_ends_with($key, '_type');
    }
}
