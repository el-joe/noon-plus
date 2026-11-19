<?php

namespace App\Domains\Identity\Support\Jwt;

/**
 * Signs a compact JWT with ES256 (P-256 + SHA-256) using openssl — used for
 * the Sign in with Apple client secret (D9). openssl returns a DER ECDSA
 * signature; JWS needs the raw 64-byte R‖S form.
 */
class Es256Signer
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $header, array $claims, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new InvalidJwt('The signing key could not be read.');
        }

        $input = Base64Url::encode((string) json_encode(['alg' => 'ES256', 'typ' => 'JWT'] + $header))
            .'.'.Base64Url::encode((string) json_encode($claims));

        if (! openssl_sign($input, $derSignature, $key, OPENSSL_ALGO_SHA256)) {
            throw new InvalidJwt('The token could not be signed.');
        }

        return $input.'.'.Base64Url::encode(self::derToRaw($derSignature));
    }

    /**
     * SEQUENCE { INTEGER r, INTEGER s } → 32-byte r ‖ 32-byte s.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($integer, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
