<?php

namespace App\Domains\Identity\Support\Jwt;

final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        if ($decoded === false) {
            throw new InvalidJwt('Malformed base64url segment.');
        }

        return $decoded;
    }
}
