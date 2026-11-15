<?php

namespace App\Domains\Returns\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Returns\Models\ReturnRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer return evidence (QC-049): kept on the private disk and opened
 * only through expiring signed links, which the customer API, the Seller
 * Center and the admin panel hand out after their own authorisation.
 * Photos stored on the public disk before the fix are still served.
 */
class ReturnPhotos
{
    public const DISK = 'local';

    public const LINK_MINUTES = 30;

    public function __construct(private CountryContext $countryContext) {}

    /**
     * @return array<int, string>
     */
    public function urls(ReturnRequest $return): array
    {
        $country = $this->countryContext->current();
        $market = $country->marketSlug(in_array(app()->getLocale(), $country->locales, true) ? app()->getLocale() : $country->default_locale);

        return array_map(fn (int $index): string => URL::temporarySignedRoute('api.v1.returns.photo', now()->addMinutes(self::LINK_MINUTES), [
            'market' => $market,
            'return' => $return->id,
            'index' => $index,
        ]), array_keys(array_values($return->photos ?? [])));
    }

    public function stream(ReturnRequest $return, int $index): StreamedResponse
    {
        $path = array_values($return->photos ?? [])[$index] ?? abort(404);

        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, ['Cache-Control' => 'private, max-age=600']);
            }
        }

        abort(404);
    }
}
