<?php

namespace App\Domains\Identity\Support\Jwt;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies an RS256 OpenID Connect identity token (Google, Apple; D9) with
 * PHP's openssl against the provider's published JWKS: signature, issuer,
 * audience, expiry and, when given, nonce. Keys are cached for an hour and
 * refetched once when a token names an unknown key id (key rotation).
 */
class JwksVerifier
{
    private const LEEWAY_SECONDS = 60;

    /**
     * @param  list<string>  $issuers
     * @param  list<string>  $audiences
     * @return array<string, mixed>
     */
    public function verify(string $jwt, string $jwksUrl, array $issuers, array $audiences, ?string $nonce = null): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new InvalidJwt('The token is not a JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode(Base64Url::decode($encodedHeader), true);
        $claims = json_decode(Base64Url::decode($encodedPayload), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw new InvalidJwt('The token header or payload is not JSON.');
        }

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidJwt('Only RS256 tokens with a key id are accepted.');
        }

        $key = $this->key($jwksUrl, $header['kid']);
        $valid = openssl_verify($encodedHeader.'.'.$encodedPayload, Base64Url::decode($encodedSignature), $key, OPENSSL_ALGO_SHA256);

        if ($valid !== 1) {
            throw new InvalidJwt('The token signature is not valid.');
        }

        $this->checkClaims($claims, $issuers, $audiences, $nonce);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>  $issuers
     * @param  list<string>  $audiences
     */
    private function checkClaims(array $claims, array $issuers, array $audiences, ?string $nonce): void
    {
        $now = time();

        if (! in_array($claims['iss'] ?? null, $issuers, true)) {
            throw new InvalidJwt('The token issuer is not accepted.');
        }

        $tokenAudiences = (array) ($claims['aud'] ?? []);

        if ($audiences === [] || array_intersect($tokenAudiences, $audiences) === []) {
            throw new InvalidJwt('The token was issued for another application.');
        }

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - self::LEEWAY_SECONDS) {
            throw new InvalidJwt('The token has expired.');
        }

        if (is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] > $now + self::LEEWAY_SECONDS) {
            throw new InvalidJwt('The token was issued in the future.');
        }

        if ($nonce !== null && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new InvalidJwt('The token nonce does not match.');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidJwt('The token has no subject.');
        }
    }

    private function key(string $jwksUrl, string $keyId): \OpenSSLAsymmetricKey
    {
        $cacheKey = 'jwks:'.sha1($jwksUrl);
        $keys = Cache::remember($cacheKey, 3600, fn (): array => $this->fetch($jwksUrl));

        if (! isset($keys[$keyId])) {
            $keys = $this->fetch($jwksUrl);
            Cache::put($cacheKey, $keys, 3600);
        }

        if (! isset($keys[$keyId])) {
            throw new InvalidJwt('The token was signed with an unknown key.');
        }

        $key = openssl_pkey_get_public($keys[$keyId]);

        if ($key === false) {
            throw new InvalidJwt('The provider key could not be read.');
        }

        return $key;
    }

    /**
     * @return array<string, string> PEM public keys by key id
     */
    private function fetch(string $jwksUrl): array
    {
        $response = Http::timeout(10)->acceptJson()->get($jwksUrl);

        if (! $response->successful()) {
            throw new InvalidJwt('The provider keys could not be loaded.');
        }

        $keys = [];

        foreach ((array) $response->json('keys', []) as $jwk) {
            if (($jwk['kty'] ?? null) === 'RSA' && isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                $keys[$jwk['kid']] = self::rsaPem(Base64Url::decode($jwk['n']), Base64Url::decode($jwk['e']));
            }
        }

        return $keys;
    }

    /**
     * DER-encode an RSA public key (modulus, exponent) as a PEM
     * SubjectPublicKeyInfo that openssl can load.
     */
    public static function rsaPem(string $modulus, string $exponent): string
    {
        $integer = fn (string $bytes): string => self::der(0x02, (ord($bytes[0]) > 0x7F ? "\x00" : '').$bytes);
        $rsaKey = self::der(0x30, $integer(ltrim($modulus, "\x00") ?: "\x00").$integer(ltrim($exponent, "\x00") ?: "\x00"));
        $algorithm = self::der(0x30, self::der(0x06, (string) hex2bin('2a864886f70d010101'))."\x05\x00");
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 0x80) {
            return chr($tag).chr($length).$value;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$value;
    }
}
