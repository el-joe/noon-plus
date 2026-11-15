<?php

namespace App\Domains\Contracts\Http\Controllers\Api;

use App\Domains\Cart\Support\CartService;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\CheckoutContracts;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contracts for the storefront and the apps (SRS §13, FR-CHK-4, MP §9
 * "mobile click-wrap enforced"): read a published version of this market,
 * accept it as a customer (customer purchase / marketer contract), and
 * `GET me/contracts` with the `consent_required` flag apps check before
 * letting the user continue (customer cart contracts, the store's Seller
 * Agreement, the marketer onboarding contract).
 */
class ContractApiController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function show(int $version, ContractRenderer $renderer): JsonResponse
    {
        $version = $this->marketVersion($version);

        return ApiResponse::success([
            'id' => $version->id,
            'kind' => $version->template->kind->value,
            'title' => $version->template->name,
            'version' => $version->version,
            'mode' => $version->template->mode,
            'text' => $version->isFile() ? null : $renderer->text($version, app()->getLocale()),
            'published_at' => $version->published_at?->toIso8601String(),
        ]);
    }

    public function accept(Request $request, int $version, ContractSigner $signer): JsonResponse
    {
        $version = $this->marketVersion($version);
        abort_unless(in_array($version->template->kind, [ContractKind::CustomerPurchase, ContractKind::MarketerOnboarding, ContractKind::Trip], true), 404);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'accept' => ['accepted'],
            'purpose' => ['nullable', 'in:purchase,rental'],
        ]);
        $user = $request->user();
        $signature = $signer->sign($version, SignerType::Customer, $user->id, $user->id, $data['full_name'], SignatureMethod::Click, $request->ip(), $request->userAgent(), locale: app()->getLocale(), purpose: $data['purpose'] ?? null);

        return ApiResponse::success(['signature_id' => $signature->id, 'version' => $signature->version, 'signed_at' => $signature->signed_at->toIso8601String(), 'sha256' => $signature->sha256], status: 201);
    }

    public function mine(Request $request, CheckoutContracts $checkout, ContractGates $gates, CartService $carts): JsonResponse
    {
        $user = $request->user();
        $pending = $checkout->missing($carts->forCustomer($user->id), $user->id, $request->header('X-Visitor-Token'), null);

        $vendor = app(VendorWorkspace::class)->resolve($user, $this->countryContext);
        $agreement = $gates->sellerAgreement();

        if ($vendor !== null && $agreement !== null && ! $gates->sellerAgreementAccepted($vendor)) {
            $pending[] = ['version_id' => $agreement->id, 'kind' => ContractKind::SellerPlatform->value, 'title' => $agreement->template->name, 'version' => $agreement->version, 'url' => route('api.v1.contracts.show', ['market' => $this->countryContext->current()->marketSlug('en'), 'version' => $agreement->id])];
        }

        $marketer = MarketerProfile::query()->where('user_id', $user->id)->first();

        if ($marketer !== null && ! $gates->marketerContractAccepted($marketer)) {
            $pending[] = ['kind' => ContractKind::MarketerOnboarding->value, 'title' => __('Marketer contract')];
        }

        return ApiResponse::success([
            'consent_required' => $pending !== [] && (bool) config('nawy.contracts.mobile_clickwrap_enforced', true),
            'contracts' => $pending,
        ]);
    }

    /**
     * The customer's signed contracts in this country (G-W29), newest first,
     * active, superseded and revoked alike — with the evidence fingerprint.
     */
    public function signed(Request $request): JsonResponse
    {
        $page = ContractSignature::query()->where('signer_user_id', $request->user()->id)
            ->latest('signed_at')->latest('id')->paginate(min(50, max(1, $request->integer('per_page', 20))));
        $versions = ContractVersion::query()->with('template')->whereIn('id', collect($page->items())->pluck('contract_version_id')->unique()->all())->get()->keyBy('id');

        return ApiResponse::success(collect($page->items())->map(fn (ContractSignature $signature): array => [
            'id' => $signature->id,
            'kind' => $signature->kind?->value,
            'title' => $versions->get($signature->contract_version_id)?->template?->name,
            'version_id' => $signature->contract_version_id,
            'version' => $signature->version,
            'status' => $signature->status,
            'method' => $signature->method?->value,
            'purpose' => $signature->purpose,
            'subject' => $signature->subject_type === null ? null : ['type' => $signature->subject_type, 'id' => $signature->subject_id],
            'full_name' => $signature->full_name,
            'locale' => $signature->locale,
            'sha256' => $signature->sha256,
            'signed_at' => $signature->signed_at?->toIso8601String(),
            'superseded_at' => $signature->superseded_at?->toIso8601String(),
            'url' => route('api.v1.contracts.show', ['market' => $this->countryContext->current()->marketSlug(app()->getLocale()), 'version' => $signature->contract_version_id]),
        ])->all(), ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }

    private function marketVersion(int $id): ContractVersion
    {
        $version = ContractVersion::query()->with('template')->where('status', ContractVersionStatus::Published)->findOrFail($id);
        abort_unless($version->template->country_id === null || $version->template->country_id === $this->countryContext->current()->id, 404);

        return $version;
    }
}
