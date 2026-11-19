<?php

namespace App\Domains\Marketers\Http\Controllers\Api;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Campaigns\Support\ReferralCodes;
use App\Domains\Campaigns\Support\ReferralQr;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Models\Order;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marketer ops app (Sanctum `ops:marketer`): the Creator Studio overview,
 * campaigns (accepted invitations), links & codes and conversions of the
 * workspace marketer, with the same queries as CreatorStudioController.
 */
class MarketerOpsController extends Controller
{
    public function __construct(private CountryContext $countryContext, private ReferralCodes $codes) {}

    /**
     * Same window as the Studio overview: the last 30 days, reversed
     * conversions left out. `sales` = attributed gross, `earnings` = net.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $marketer = $this->marketer($request);
        $since = now()->subDays(30);
        $conversions = MarketerConversion::query()->where('marketer_profile_id', $marketer->id)->where('created_at', '>=', $since)->where('status', '!=', ConversionStatus::Reversed);
        $topCode = $marketer->invitations()->where('status', InvitationStatus::Accepted)->latest('id')->first();

        return ApiResponse::success([
            'profile' => [
                'id' => $marketer->id,
                'name' => $marketer->display_name,
                'types' => array_values((array) $marketer->job_types),
                'code' => $topCode?->referral_code,
            ],
            'kpis' => [
                'clicks' => ReferralClick::query()->where('marketer_profile_id', $marketer->id)->where('clicked_at', '>=', $since)->count(),
                'conversions' => (clone $conversions)->count(),
                'sales' => MoneyResource::make((string) (clone $conversions)->sum('gross')),
                'earnings' => MoneyResource::make((string) (clone $conversions)->sum('net')),
                'active_campaigns' => $marketer->invitations()->where('status', InvitationStatus::Accepted)
                    ->whereHas('campaign', fn ($query) => $query->whereIn('status', [CampaignStatus::Active, CampaignStatus::AutoApproved]))->count(),
            ],
        ]);
    }

    /**
     * The marketer's campaigns = accepted invitations (`id` is the invitation id).
     */
    public function campaigns(Request $request): JsonResponse
    {
        $invitations = $this->accepted($this->marketer($request));
        $conversions = MarketerConversion::query()->whereIn('invitation_id', $invitations->pluck('id'))
            ->selectRaw('invitation_id, count(*) as aggregate')->groupBy('invitation_id')->pluck('aggregate', 'invitation_id');

        return ApiResponse::success($invitations->map(fn (CampaignInvitation $invitation): array => [
            'id' => $invitation->id,
            'campaign_id' => $invitation->campaign_id,
            'name' => $invitation->campaign?->title,
            'status' => $invitation->campaign?->status?->value,
            'seller' => $invitation->campaign?->vendor?->store_name,
            'commission' => $this->commission($invitation),
            'code' => $invitation->referral_code,
            'starts_at' => $invitation->campaign?->live_at?->toIso8601String(),
            'ends_at' => $invitation->campaign?->ends_at?->toIso8601String(),
            'link' => $invitation->referral_code ? $this->share($invitation->referral_code) : null,
            'conversions' => (int) ($conversions[$invitation->id] ?? 0),
        ])->all());
    }

    public function links(Request $request): JsonResponse
    {
        $invitations = $this->accepted($this->marketer($request));
        $top = $invitations->first(fn (CampaignInvitation $invitation): bool => filled($invitation->referral_code));

        return ApiResponse::success([
            'referral_link' => $top === null ? null : $this->share($top->referral_code),
            'links' => $invitations->filter(fn (CampaignInvitation $invitation): bool => filled($invitation->referral_code))
                ->map(fn (CampaignInvitation $invitation): array => [
                    'invitation_id' => $invitation->id,
                    'campaign' => (string) $invitation->campaign?->title,
                    'code' => $invitation->referral_code,
                    'url' => $this->share($invitation->referral_code),
                    'qr_url' => route('api.v1.ops.marketer.links.qr', ['market' => $request->attributes->get('market'), 'invitation' => $invitation->id]),
                    'clicks' => ReferralClick::query()->where('invitation_id', $invitation->id)->count(),
                    'conversions' => MarketerConversion::query()->where('invitation_id', $invitation->id)->distinct('order_id')->count('order_id'),
                ])->values()->all(),
        ]);
    }

    /**
     * D16: the QR code of one of the marketer's referral links (accepted
     * invitations only), `format=svg` (default) or `png`, as a download.
     */
    public function linkQr(Request $request, int $invitation, ReferralQr $qr): Response
    {
        $row = $this->accepted($this->marketer($request))->first(fn (CampaignInvitation $candidate): bool => $candidate->id === $invitation && filled($candidate->referral_code));
        abort_if($row === null, 404);
        $format = $request->validate(['format' => ['nullable', 'in:svg,png']])['format'] ?? 'svg';

        return $qr->response($this->codes->share($row->referral_code, $this->countryContext->current())['qr_payload'], $row->referral_code, $format);
    }

    /**
     * `amount` = the attributed sale (gross), `commission` = the marketer's net.
     */
    public function conversions(Request $request): JsonResponse
    {
        $page = MarketerConversion::query()->where('marketer_profile_id', $this->marketer($request)->id)
            ->with('campaign:id,title')->latest('id')->paginate(30);
        $orders = Order::query()->whereIn('id', $page->getCollection()->pluck('order_id')->filter())->pluck('number', 'id');

        return ApiResponse::success($page->getCollection()->map(fn (MarketerConversion $conversion): array => [
            'id' => $conversion->id,
            'order_number' => $orders[$conversion->order_id] ?? null,
            'campaign' => $conversion->campaign?->title,
            'amount' => MoneyResource::of((string) $conversion->gross, $conversion->currency),
            'commission' => MoneyResource::of((string) $conversion->net, $conversion->currency),
            'status' => $conversion->status->value,
            'occurred_at' => $conversion->created_at?->toIso8601String(),
        ])->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    private function marketer(Request $request): MarketerProfile
    {
        return $request->attributes->get('ops_workspace');
    }

    /**
     * @return Collection<int, CampaignInvitation>
     */
    private function accepted(MarketerProfile $marketer): Collection
    {
        return $marketer->invitations()->where('status', InvitationStatus::Accepted)->with('campaign.vendor')->latest('id')->get();
    }

    private function share(string $code): string
    {
        return $this->codes->share($code, $this->countryContext->current())['url'];
    }

    /**
     * Pay per sale as the Studio shows it: fixed fee, broker fixed + %, or %.
     */
    private function commission(CampaignInvitation $invitation): ?string
    {
        $rates = $invitation->rate_snapshot ?? [];
        $number = fn (mixed $value): string => str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;

        return match (true) {
            $invitation->influencer_fixed !== null && bccomp((string) $invitation->influencer_fixed, '0', 4) > 0 => MoneyResource::of((string) $invitation->influencer_fixed, $invitation->campaign?->currency)['amount'].' '.($invitation->campaign?->currency ?? '').' '.__('fixed'),
            $invitation->effective_type === 'broker' => $number($rates['broker_fixed'] ?? 0).' + '.$number($rates['broker_percent'] ?? 0).'%',
            isset($rates['affiliate_percent']) => $number($rates['affiliate_percent']).'%',
            default => null,
        };
    }
}
