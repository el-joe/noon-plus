<?php

namespace App\Domains\Marketers\Http\Controllers\Api;

use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Catalog\Support\ListingCards;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public creator directory `/creators` and creator boutique
 * `/creator/{slug}` data (SRS §11.13, Figma 57:2752, 59:3228): active
 * marketers of the market only; picks are the listings of their live
 * accepted campaigns, each with the creator's referral code.
 */
class CreatorDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $creators = MarketerProfile::query()->where('global_status', MarketerStatus::Active)
            ->when($request->query('type'), fn ($query, $type) => $query->whereJsonContains('job_types', (string) $type))
            ->when($request->query('q'), fn ($query, $q) => $query->where('display_name', 'like', '%'.$q.'%'))
            ->orderByDesc('is_verified')->orderByDesc('followers')->orderBy('id')
            ->paginate(min(48, max(1, $request->integer('per_page', 24))));

        return ApiResponse::success(collect($creators->items())->map(fn (MarketerProfile $creator): array => $this->card($creator))->all(), [
            'total' => $creators->total(), 'page' => $creators->currentPage(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $creator = MarketerProfile::query()->where('slug', $slug)->where('global_status', MarketerStatus::Active)->firstOrFail();
        $picks = CampaignInvitation::query()->where('marketer_profile_id', $creator->id)->where('status', InvitationStatus::Accepted)
            ->whereHas('campaign', fn ($query) => $query->whereIn('status', ['active', 'auto_approved']))
            ->with(['campaign', ...ListingCards::relations('campaign.listing.')])->latest('id')->limit(24)->get();

        return ApiResponse::success($this->card($creator) + [
            'bio' => $creator->bio,
            'socials' => $creator->socials ?? [],
            'picks' => $picks->filter(fn (CampaignInvitation $invitation): bool => $invitation->campaign->listing !== null)->values()->map(fn (CampaignInvitation $invitation): array => ListingCards::card($invitation->campaign->listing) + [
                'product_slug' => $invitation->campaign->listing->variant?->product?->slug,
                'referral_code' => $invitation->referral_code,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(MarketerProfile $creator): array
    {
        return [
            'slug' => $creator->slug,
            'name' => $creator->display_name,
            'job_types' => $creator->job_types,
            'avatar_url' => PublicUrl::of($creator->avatar_path),
            'cover_url' => PublicUrl::of($creator->cover_path),
            'followers' => $creator->followers,
            'verified' => $creator->is_verified,
        ];
    }
}
