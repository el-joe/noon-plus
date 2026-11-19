<?php

namespace App\Domains\Campaigns\Http\Controllers\Api;

use App\Domains\Campaigns\Enums\ReferralStatus;
use App\Domains\Campaigns\Support\Attribution;
use App\Domains\Campaigns\Support\ReferralCodes;
use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Support\CreatorPicks;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Referral landing data `/r/{code}` (SRS §11.8, Figma 71:11488): records
 * the click for the visitor token (header `X-Visitor-Token` or query) and
 * the signed-in customer, and returns the validity status, the product to
 * land on (a product card), the creator's other live picks (G-W30) and the
 * share/QR payload.
 */
class ReferralController extends Controller
{
    public function show(Request $request, string $code, Attribution $attribution, ReferralCodes $codes, CountryContext $countryContext, CreatorPicks $creatorPicks): JsonResponse
    {
        $visitor = $request->header('X-Visitor-Token') ?? $request->query('visitor_token');
        $customer = auth('sanctum')->user();
        $result = $attribution->recordClick($code, is_string($visitor) ? $visitor : null, $customer?->id, $request->ip(), $request->userAgent());
        $invitation = $result['invitation'];

        if ($result['status'] === ReferralStatus::Unknown) {
            return ApiResponse::error('referral_unknown', __('This referral link does not exist.'), 404);
        }

        $listing = $invitation->campaign->listing()->with(ListingCards::relations())->first();
        $picks = $creatorPicks->public($invitation->marketer, 12, $invitation->id)
            ->reject(fn (array $pick): bool => $pick['listing']->id === $listing->id)->values();

        return ApiResponse::success([
            'status' => $result['status']->value,
            'tracked' => $result['click'] !== null,
            'creator' => ['name' => $invitation->marketer->display_name, 'slug' => $invitation->marketer->slug],
            'campaign' => ['title' => $invitation->campaign->title],
            'destination' => ListingCards::card($listing) + ['product_slug' => $listing->variant->product->slug],
            'picks' => $picks->map(fn (array $pick): array => ListingCards::card($pick['listing']) + ['product_slug' => $pick['listing']->variant?->product?->slug, 'referral_code' => $pick['referral_code']])->all(),
            'share' => $codes->share($invitation->referral_code, $countryContext->current()),
        ]);
    }
}
