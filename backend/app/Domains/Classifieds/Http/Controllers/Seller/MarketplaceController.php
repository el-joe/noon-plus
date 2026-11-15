<?php

namespace App\Domains\Classifieds\Http\Controllers\Seller;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Classifieds\Models\ClassifiedFavourite;
use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Classifieds\Models\PhoneReveal;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedInquiries;
use App\Domains\Classifieds\Support\ClassifiedSpecs;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seller Center → Marketplace for classified business shops (Figma
 * 102:10964 My ads, 102:11429 create ad, 103:5462 mark sold, 103:5763
 * inquiries). Every query is scoped to the workspace shop.
 */
class MarketplaceController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private ClassifiedAds $ads,
        private ClassifiedAdMachine $machine,
        private CountryContext $countryContext,
    ) {}

    public function index(Request $request): View
    {
        $shop = $this->shop();
        $status = $request->query('status');
        $ads = ClassifiedAd::query()->where('vendor_id', $shop->id)
            ->when(is_string($status) && ClassifiedAdStatus::tryFrom($status) !== null, fn ($query) => $query->where('status', $status))
            ->with(['category', 'media'])->withCount(['threads'])->latest('id')->paginate(20);
        $adIds = ClassifiedAd::query()->where('vendor_id', $shop->id)->pluck('id');
        $since = now()->subDays(30);

        return view('panels.partner.marketplace.ads', [
            'ads' => $ads,
            'status' => $status,
            'currency' => $this->countryContext->current()->currency,
            'counts' => ClassifiedAd::query()->where('vendor_id', $shop->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'stats' => [
                'views' => (int) ClassifiedAd::query()->whereKey($adIds)->sum('views'),
                'reveals' => PhoneReveal::query()->whereIn('classified_ad_id', $adIds)->where('created_at', '>=', $since)->count(),
                'chats' => ClassifiedThread::query()->whereIn('classified_ad_id', $adIds)->where('created_at', '>=', $since)->count(),
                'saves' => ClassifiedFavourite::query()->whereIn('classified_ad_id', $adIds)->count(),
            ],
        ]);
    }

    public function create(Request $request, ClassifiedSpecs $specs): View
    {
        $categories = ClassifiedCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name_en')->get();
        $category = $categories->firstWhere('id', (int) $request->query('category'));

        return view('panels.partner.marketplace.create', [
            'categories' => $categories,
            'category' => $category,
            'schema' => $category === null ? collect() : $specs->describe($category),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'kind' => ['required', 'in:offer,wanted'],
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'city' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_phone' => ['nullable', 'string', 'max:24'],
            'specs' => ['nullable', 'array'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*' => ['image', 'max:8192'],
        ]);
        $data['specs'] = array_filter($data['specs'] ?? [], fn ($value) => $value !== null && $value !== '');
        $ad = $this->ads->create($request->user()->id, $data, ActorType::Vendor, $this->shop());

        foreach ($request->file('photos', []) as $photo) {
            $this->ads->addPhoto($ad, $photo);
        }

        if ($request->boolean('submit')) {
            $ad = $this->machine->submit($ad->refresh(), ActorType::Vendor, $request->user()->id);
        }

        return redirect()->route('partner.marketplace.ads')->with('status', match ($ad->status) {
            ClassifiedAdStatus::PendingContract => __('Sign the category contract to send the ad to review.'),
            ClassifiedAdStatus::PendingReview => __('Ad sent to review. It goes live once approved.'),
            default => __('Draft saved.'),
        });
    }

    public function act(Request $request, int $ad, string $action): RedirectResponse
    {
        $ad = $this->ad($ad);
        $userId = $request->user()->id;

        match ($action) {
            'submit' => $this->machine->submit($ad, ActorType::Vendor, $userId),
            'sign' => $this->machine->signContract($ad, (string) $request->validate(['full_name' => ['required', 'string', 'min:3', 'max:160']])['full_name'], ActorType::Vendor, $userId, $request->ip(), $request->userAgent()),
            'pause' => $this->machine->pause($ad, ActorType::Vendor, $userId),
            'resume' => $this->machine->resume($ad, ActorType::Vendor, $userId),
            'renew' => $this->machine->renew($ad, ActorType::Vendor, $userId),
            'archive' => $this->machine->archive($ad, ActorType::Vendor, $userId),
            default => abort(404),
        };

        return back()->with('status', __('Ad updated.'));
    }

    public function soldForm(int $ad): View
    {
        $ad = $this->ad($ad);

        return view('panels.partner.marketplace.sold', ['ad' => $ad, 'referrers' => $this->machine->referrers($ad), 'currency' => $ad->currency]);
    }

    public function markSold(Request $request, int $ad): RedirectResponse
    {
        $data = $request->validate(['final_price' => ['required', 'numeric', 'gt:0'], 'marketer_profile_id' => ['nullable', 'integer'], 'buyer_note' => ['nullable', 'string', 'max:255']]);
        $this->machine->markSold($this->ad($ad), ActorType::Vendor, $request->user()->id, (string) $data['final_price'], isset($data['marketer_profile_id']) ? (int) $data['marketer_profile_id'] : null, $data['buyer_note'] ?? null);

        return redirect()->route('partner.marketplace.ads')->with('status', __('Marked as sold.'));
    }

    public function inquiries(): View
    {
        $threads = ClassifiedThread::query()->whereHas('ad', fn ($query) => $query->where('vendor_id', $this->shop()->id))
            ->with(['ad', 'messages' => fn ($query) => $query->orderBy('id')])->latest('last_message_at')->paginate(20);

        return view('panels.partner.marketplace.inquiries', ['threads' => $threads]);
    }

    public function reply(Request $request, int $thread, ClassifiedInquiries $inquiries): RedirectResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:4000']])['body'];
        $thread = ClassifiedThread::query()->whereHas('ad', fn ($query) => $query->where('vendor_id', $this->shop()->id))->findOrFail($thread);
        $inquiries->reply($thread, (int) $thread->seller_user_id, $body);

        return back()->with('status', __('Reply sent.'));
    }

    private function shop(): Vendor
    {
        $vendor = $this->workspace->vendor();
        abort_unless($vendor->type === 'classified', 403, __('Marketplace pages are for classified shops.'));

        return $vendor;
    }

    private function ad(int $id): ClassifiedAd
    {
        return ClassifiedAd::query()->where('vendor_id', $this->shop()->id)->with(['category', 'vendor', 'media', 'property'])->findOrFail($id);
    }
}
