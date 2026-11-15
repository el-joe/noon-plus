<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Countries\Models\Country;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fresh 10-character referral codes, never reused (SRS §11.7). The central
 * `referral_codes` registry keeps them unique across countries so a bare
 * `/r/{code}` link finds its country.
 */
class ReferralCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function reserve(Country $country): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            try {
                DB::table('referral_codes')->insert(['code' => $code, 'country_id' => $country->id, 'invitation_id' => 0, 'created_at' => now()]);

                return $code;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return Str::upper(Str::random(10));
    }

    public function bind(string $code, int $invitationId): void
    {
        DB::table('referral_codes')->where('code', $code)->update(['invitation_id' => $invitationId]);
    }

    public function countryOf(string $code): ?Country
    {
        $countryId = DB::table('referral_codes')->where('code', strtoupper($code))->value('country_id');

        return $countryId === null ? null : Country::query()->find($countryId);
    }

    /**
     * The link and the QR payload for a code (the QR image is drawn by the
     * client from this payload; no image package is installed).
     *
     * @return array{code: string, url: string, qr_payload: string}
     */
    public function share(string $code, Country $country): array
    {
        $url = rtrim((string) config('nawy.payments.storefront_url'), '/').'/r/'.$code;

        return ['code' => $code, 'url' => $url, 'qr_payload' => $url.'?utm_source=qr&market='.$country->marketSlug('en')];
    }
}
