<?php

namespace App\Domains\Advertising\Http\Controllers\Api;

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Domains\Advertising\Models\AdBooking;
use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Support\AdCampaigns;
use App\Domains\Advertising\Support\SponsoredRanker;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Ad delivery for the storefront and the app: sponsored product clicks
 * (the encrypted `ad_token` from {@see SponsoredRanker}) and the live paid
 * placements of a page (homepage / category slots), always labelled "Ad".
 */
class AdController extends Controller
{
    public function click(Request $request, AdCampaigns $campaigns): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:2048']])['token'];

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return ApiResponse::error('invalid_ad_token', __('This ad link is not valid.'), 422);
        }

        $campaign = AdCampaign::query()->findOrFail((int) ($payload['c'] ?? 0));
        $visitor = $request->user('sanctum')?->id !== null ? 'u'.$request->user('sanctum')->id : ($request->header('X-Visitor-Token') ?? $request->ip());
        $event = $campaigns->click($campaign, (string) ($payload['pl'] ?? 'search'), $visitor);

        return ApiResponse::success(['product' => $payload['p'] ?? null, 'billable' => $event->is_billable]);
    }

    public function placements(Request $request, string $placement, VerticalSettings $settings): JsonResponse
    {
        $category = $request->validate(['category_id' => ['nullable', 'integer']])['category_id'] ?? null;
        $label = app()->getLocale() === 'ar' ? $settings->string('advertising.label_ar') : $settings->string('advertising.label_en');
        $bookings = AdBooking::query()->where('status', AdBookingStatus::Active)
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())
            ->whereHas('slot', fn ($slot) => $slot->where('placement', $placement)->when($category !== null, fn ($query) => $query->where('category_id', $category)))
            ->with('slot')->orderBy('id')->get();

        return ApiResponse::success($bookings->map(fn (AdBooking $booking): array => [
            'slot' => $booking->slot->code,
            'image' => $booking->creative_path === null || $booking->creative_type === 'video' ? null : Storage::disk('public')->url($booking->creative_path),
            // QC-018: admin-uploaded video creative (ADR 11).
            'video' => $booking->creative_path !== null && $booking->creative_type === 'video' ? Storage::disk('public')->url($booking->creative_path) : null,
            'creative_type' => $booking->creative_type,
            'headline' => $booking->headline,
            'link_url' => $booking->link_url,
            'sponsored' => true,
            'label' => $label,
        ])->all());
    }
}
