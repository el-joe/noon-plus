<?php

namespace App\Domains\Marketers\Http\Controllers\Studio;

use App\Domains\Campaigns\Support\ReferralCodes;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Models\MarketerPick;
use App\Domains\Marketers\Support\CreatorPicks;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Creator Studio → My products: the creator's curated picks shown on their
 * creator page and referral landing page. Search the country's active
 * catalogue, add, remove and reorder; each pick shows its referral link and
 * stats when a live campaign of the creator promotes it, and the creator
 * page link otherwise. Scoped to the workspace marketer.
 */
class MarketerPicksController extends Controller
{
    public function __construct(private MarketerWorkspace $workspace, private CountryContext $countryContext, private CreatorPicks $picks) {}

    public function index(Request $request, ReferralCodes $codes): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $marketer = $this->workspace->marketer();
        $country = $this->countryContext->current();
        $rows = $this->picks->studioRows($marketer);
        $search = trim((string) ($filters['q'] ?? ''));

        $results = $search === '' ? collect() : Listing::query()->where('status', ListingStatus::Active)
            ->whereNotIn('id', $rows->pluck('pick.listing_id'))
            ->whereHas('variant.product', fn ($product) => $product->where(fn ($query) => $query
                ->where('title_en', 'like', LikeSearch::contains($search))
                ->orWhere('title_ar', 'like', LikeSearch::contains($search))
                ->when(ctype_digit($search), fn ($inner) => $inner->orWhere('id', (int) $search))))
            ->with('variant.product', 'vendor')->latest('id')->limit(20)->get();

        return view('panels.marketer.products', [
            'marketer' => $marketer,
            'country' => $country,
            'rows' => $rows->map(fn (array $row): array => $row + [
                'share' => $row['invitation'] ? $codes->share($row['invitation']->referral_code, $country) : null,
            ]),
            'results' => $results,
            'search' => $search,
            'max' => $this->picks->max(),
            'creatorUrl' => rtrim((string) config('nawy.payments.storefront_url'), '/').'/'.$country->marketSlug('en').'/creators/'.$marketer->slug,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $marketer = $this->workspace->marketer();
        $data = $request->validate([
            'listing_id' => ['required', 'integer', Rule::exists(Listing::class, 'id')->where('status', ListingStatus::Active->value)],
        ]);

        if (MarketerPick::query()->where('marketer_profile_id', $marketer->id)->where('listing_id', $data['listing_id'])->exists()) {
            throw ValidationException::withMessages(['listing_id' => __('This product is already one of your picks.')]);
        }

        if (MarketerPick::query()->where('marketer_profile_id', $marketer->id)->count() >= $this->picks->max()) {
            throw ValidationException::withMessages(['listing_id' => __('You can have at most :max picks. Remove one first.', ['max' => $this->picks->max()])]);
        }

        $this->picks->add($marketer, Listing::query()->findOrFail($data['listing_id']));

        return back()->with('status', __('Added to your picks.'));
    }

    public function move(Request $request, int $pick): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $marketer = $this->workspace->marketer();
        $this->picks->move($marketer, $this->find($pick), $data['direction']);

        return back()->with('status', __('Order saved.'));
    }

    public function destroy(int $pick): RedirectResponse
    {
        $this->find($pick)->delete();

        return back()->with('status', __('Removed from your picks.'));
    }

    private function find(int $pick): MarketerPick
    {
        return MarketerPick::query()->where('marketer_profile_id', $this->workspace->marketer()->id)->findOrFail($pick);
    }
}
