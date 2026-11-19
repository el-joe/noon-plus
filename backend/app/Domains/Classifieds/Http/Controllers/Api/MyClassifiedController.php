<?php

namespace App\Domains\Classifieds\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Http\ClassifiedPresenter;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedPackage;
use App\Domains\Classifieds\Support\ClassifiedAdMachine;
use App\Domains\Classifieds\Support\ClassifiedAds;
use App\Domains\Classifieds\Support\ClassifiedPackages;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Http\Resources\PaymentResource;
use App\Domains\RealEstate\Http\ViewingPresenter;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\RealEstate\Support\Viewings;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\VerticalSettings;
use App\Shared\Validation\CountryPhone;
use Brick\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in owner's ads (post-ad wizard and My ads in the app):
 * drafts, media, submission with the contract step (FR-CLS-1), pause /
 * resume / archive / renew, mark sold with the attribution picker, and
 * packages. Every action is limited to the owner's own ads.
 */
class MyClassifiedController extends Controller
{
    public function __construct(private ClassifiedAds $ads, private ClassifiedAdMachine $machine) {}

    public function index(Request $request): JsonResponse
    {
        $ads = ClassifiedAd::query()->where('owner_user_id', $request->user()->id)->with(['media', 'category', 'vendor'])->latest('id')->paginate(24);

        return ApiResponse::success(collect($ads->items())->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad) + ['views' => $ad->views])->all(), ['total' => $ads->total(), 'page' => $ads->currentPage(), 'per_page' => $ads->perPage(), 'last_page' => $ads->lastPage()]);
    }

    /**
     * Viewing / reserve requests received on the owner's ads, newest first,
     * with the customer's contact details (owner decision D12: a record
     * only; the owner contacts the customer outside Nawy).
     */
    public function requests(Request $request, Viewings $viewings): JsonResponse
    {
        $requests = $viewings->received(['owner_user_id' => $request->user()->id], $request->query('type') === null ? null : (string) $request->query('type'))
            ->with(['ad.media', 'ad.category', 'ad.vendor'])->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success(collect($requests->items())->map(fn (PropertyViewing $viewing): array => ViewingPresenter::record($viewing, withContact: true))->all(), ['total' => $requests->total(), 'page' => $requests->currentPage(), 'per_page' => $requests->perPage(), 'last_page' => $requests->lastPage()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);
        $user = $request->user();
        $shop = ($data['as_shop'] ?? false) ? Vendor::query()->where('owner_user_id', $user->id)->where('type', 'classified')->firstOrFail() : null;
        $broker = ($data['as_broker'] ?? false) ? MarketerProfile::query()->where('user_id', $user->id)->firstOrFail() : null;

        if ($broker !== null) {
            $this->machine->assertBrokerCanPublish($broker);
        }

        $ad = $this->ads->create($user->id, $data, ActorType::Customer, $shop, $broker);

        return ApiResponse::success(ClassifiedPresenter::detail($ad->load(['media', 'category', 'vendor', 'marketer', 'property'])), status: 201);
    }

    public function update(Request $request, string $number): JsonResponse
    {
        $ad = $this->ad($request, $number);

        return ApiResponse::success(ClassifiedPresenter::detail($this->ads->update($ad, $this->validated($request, creating: false))->load(['media', 'category', 'vendor', 'marketer', 'property'])));
    }

    public function photo(Request $request, string $number, VerticalSettings $settings): JsonResponse
    {
        $ad = $this->ad($request, $number);
        // The category's own limit (photoLimits); the count is checked by addPhoto (max_photos).
        $file = $request->validate(['photo' => ['required', 'image', 'max:'.($ad->category?->photoLimits()['photo_max_kb'] ?? $settings->int('classified.photo_max_kb'))]])['photo'];
        $media = $this->ads->addPhoto($ad, $file);

        return ApiResponse::success(['id' => $media->id, 'type' => 'photo'], status: 201);
    }

    public function video(Request $request, string $number, VerticalSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:'.$settings->int('classified.video_max_kb')],
            'duration_seconds' => ['required', 'integer', 'min:1'],
        ]);
        $media = $this->ads->addVideo($this->ad($request, $number), $data['video'], (int) $data['duration_seconds']);

        return ApiResponse::success(['id' => $media->id, 'type' => 'video'], status: 201);
    }

    public function removeMedia(Request $request, string $number, int $media): JsonResponse
    {
        $this->ads->removeMedia($this->ad($request, $number), $media);

        return ApiResponse::success(['deleted' => true]);
    }

    public function submit(Request $request, string $number): JsonResponse
    {
        $ad = $this->machine->submit($this->ad($request, $number), ActorType::Customer, $request->user()->id);
        $contract = $ad->status->value === 'pending_contract' ? $this->machine->sellerContract($ad) : null;

        return ApiResponse::success(['number' => $ad->number, 'status' => $ad->status->value, 'contract' => $contract === null ? null : [
            'version_id' => $contract->version->id,
            'title' => $contract->version->template->name,
            'version' => $contract->version->version,
        ]]);
    }

    /**
     * The contract a pending-contract ad waits for (G-W24), so a reopened
     * ad can show the text again; null when none is needed.
     */
    public function contract(Request $request, string $number, ContractRenderer $renderer): JsonResponse
    {
        $ad = $this->ad($request, $number);
        $contract = $ad->category->needsContract() ? $this->machine->sellerContract($ad) : null;

        return ApiResponse::success([
            'number' => $ad->number,
            'status' => $ad->status->value,
            'contract' => $contract === null ? null : [
                'version_id' => $contract->version->id,
                'title' => $contract->version->template->name,
                'version' => $contract->version->version,
                'text' => $contract->version->isFile() ? null : $renderer->text($contract->version, app()->getLocale()),
                'has_file' => $contract->version->isFile(),
                'signed' => $ad->status !== ClassifiedAdStatus::PendingContract && $ad->status !== ClassifiedAdStatus::Draft,
            ],
        ]);
    }

    /**
     * Copy a rejected / expired / archived / sold ad into a new draft (G-W24).
     */
    public function duplicate(Request $request, string $number): JsonResponse
    {
        $copy = $this->ads->duplicate($this->ad($request, $number));

        return ApiResponse::success(ClassifiedPresenter::detail($copy->load(['media', 'category', 'vendor', 'marketer', 'property'])), status: 201);
    }

    public function signContract(Request $request, string $number): JsonResponse
    {
        $name = $request->validate(['full_name' => ['required', 'string', 'min:3', 'max:160']])['full_name'];
        $ad = $this->machine->signContract($this->ad($request, $number), $name, ActorType::Customer, $request->user()->id, $request->ip(), $request->userAgent());

        return ApiResponse::success(['number' => $ad->number, 'status' => $ad->status->value]);
    }

    public function action(Request $request, string $number, string $action): JsonResponse
    {
        $ad = $this->ad($request, $number);
        $userId = $request->user()->id;

        $ad = match ($action) {
            'pause' => $this->machine->pause($ad, ActorType::Customer, $userId),
            'resume' => $this->machine->resume($ad, ActorType::Customer, $userId),
            'archive' => $this->machine->archive($ad, ActorType::Customer, $userId),
            'renew' => $this->machine->renew($ad, ActorType::Customer, $userId),
            'unsold' => $this->machine->unmarkSold($ad, ActorType::Customer, $userId),
            default => abort(404),
        };

        return ApiResponse::success(['number' => $ad->number, 'status' => $ad->status->value, 'expires_at' => $ad->expires_at?->toIso8601String()]);
    }

    /**
     * Mark sold (SRS §11.12): final price, optional referring marketer from the picker, buyer note.
     */
    public function sold(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'final_price' => ['required', 'numeric', 'gt:0'],
            'marketer_profile_id' => ['nullable', 'integer'],
            'buyer_note' => ['nullable', 'string', 'max:255'],
        ]);
        $ad = $this->machine->markSold($this->ad($request, $number), ActorType::Customer, $request->user()->id, (string) $data['final_price'], isset($data['marketer_profile_id']) ? (int) $data['marketer_profile_id'] : null, $data['buyer_note'] ?? null);

        return ApiResponse::success(['number' => $ad->number, 'status' => $ad->status->value, 'sold_price' => MoneyResource::of((string) $ad->sold_price, $ad->currency)]);
    }

    public function referrers(Request $request, string $number): JsonResponse
    {
        $referrers = $this->machine->referrers($this->ad($request, $number));

        return ApiResponse::success(collect($referrers)->map(fn (array $row, int $id): array => ['marketer_profile_id' => $id] + $row)->values()->all());
    }

    public function buyPackage(Request $request, string $number, ClassifiedPackages $packages): JsonResponse
    {
        $data = $request->validate(['package' => ['required', 'string', 'max:32'], 'payment_method' => ['required', 'string', 'max:24']]);
        $package = ClassifiedPackage::query()->where('code', $data['package'])->firstOrFail();
        [$purchase, $payment] = $packages->buy($this->ad($request, $number), $package, $request->user(), $data['payment_method']);

        return ApiResponse::success([
            'purchase' => ['amount' => MoneyResource::of((string) $purchase->refresh()->amount, $purchase->currency)] + $purchase->only(['number', 'status', 'currency', 'applied_until']),
            'payment' => new PaymentResource($payment),
        ], status: 201);
    }

    private function ad(Request $request, string $number): ClassifiedAd
    {
        return ClassifiedAd::query()->where('number', $number)->where('owner_user_id', $request->user()->id)->with(['category', 'vendor', 'marketer', 'property', 'media'])->firstOrFail();
    }

    /**
     * QA-020: the contact phone is a number of the market (stored E.164);
     * QA-040: the price has at most the currency's decimals.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $decimals = Currency::of(app(CountryContext::class)->current()->currency)->getDefaultFractionDigits();

        $data = $request->validate([
            'category_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'kind' => ['sometimes', 'in:offer,wanted'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0', 'decimal:0,'.$decimals],
            'is_negotiable' => ['sometimes', 'boolean'],
            'city' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_phone' => ['nullable', 'string', 'max:24', new CountryPhone],
            'specs' => ['nullable', 'array'],
            'as_shop' => ['sometimes', 'boolean'],
            'as_broker' => ['sometimes', 'boolean'],
            'property' => ['nullable', 'array'],
            'property.purpose' => ['required_with:property', 'in:rent,sale,off_plan'],
            'property.property_type' => ['required_with:property', 'string', 'max:24'],
            'property.bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'property.bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'property.area_sqm' => ['nullable', 'numeric', 'min:1'],
            'property.furnishing' => ['nullable', 'in:furnished,semi,unfurnished'],
            'property.rent_period' => ['nullable', 'in:yearly,monthly,weekly,daily'],
            'property.permit_number' => ['nullable', 'string', 'max:64'],
            'property.permit_authority' => ['nullable', 'string', 'max:64'],
            'property.permit_expires_on' => ['nullable', 'date'],
            'property.completion_on' => ['nullable', 'date'],
            'property.developer' => ['nullable', 'string', 'max:255'],
            'property.payment_plan' => ['nullable', 'array'],
            'property.features' => ['nullable', 'array'],
        ]);

        if (filled($data['contact_phone'] ?? null)) {
            $data['contact_phone'] = CountryPhone::normalize($data['contact_phone']);
        }

        return $data;
    }
}
