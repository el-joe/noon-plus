<?php

namespace App\Domains\Onboarding\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Countries\Support\DomainMap;
use App\Domains\Finance\Http\Controllers\Admin\AcquisitionCommissionController;
use App\Models\User;

/**
 * Account-manager referral links for partner applications (FR-FIN-6).
 *
 * An account manager (an active admin of the country, the same people
 * {@see AcquisitionCommissionController::acquirersFor()} lists) shares
 * `{storefront}/{market}/join?ref=AM{id}-{check}`. The code is the user id
 * plus a short HMAC with the app key, so it cannot be guessed or changed to
 * another id, and no extra table is needed. The wizard sends it with the
 * account step; a valid code records the acquirer on the application, and
 * approval credits the new store to them.
 */
class AcquirerReferrals
{
    public function __construct(private CountryContext $countryContext) {}

    public function code(User|int $user): string
    {
        $id = $user instanceof User ? $user->id : $user;

        return 'AM'.$id.'-'.$this->check($id);
    }

    /**
     * The acquirer a code names, when it is genuine and the user can be an
     * acquirer in this country; null otherwise (an unknown code is ignored,
     * never an error for the applicant).
     */
    public function resolve(?string $code): ?int
    {
        if ($code === null || ! preg_match('/^AM(\d{1,12})-([a-z0-9]{8})$/i', trim($code), $match)) {
            return null;
        }

        $id = (int) $match[1];

        if (! hash_equals($this->check($id), strtolower($match[2]))) {
            return null;
        }

        return $this->isAcquirer($id) ? $id : null;
    }

    public function isAcquirer(int $userId): bool
    {
        return AcquisitionCommissionController::acquirersFor($this->countryContext->current()->id)->contains('id', $userId);
    }

    /**
     * The join link an account manager shares, in the country's storefront.
     */
    public function link(User $user, ?string $locale = null): string
    {
        $country = $this->countryContext->current();
        $locale ??= $country->default_locale;

        return app(DomainMap::class)->storefrontBase($country, $locale).'/'.$country->marketSlug($locale).'/join?'.http_build_query(['ref' => $this->code($user)]);
    }

    private function check(int $id): string
    {
        return substr(hash_hmac('sha256', 'acquirer:'.$id, (string) config('app.key')), 0, 8);
    }
}
