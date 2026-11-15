<?php

namespace App\Domains\Contracts\Http\Controllers\Seller;

use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller Center → Contracts (SRS §13, matrix §4 `legal`, `pending_contracts`,
 * `contract_history`): the Seller Agreement (click-wrap), the individual
 * contracts of categories that require one, the collaboration contracts
 * for marketer campaigns, signing with a typed name, and the signed copies.
 */
class SellerContractController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private CountryContext $countryContext,
        private ContractGates $gates,
        private ContractResolver $resolver,
        private ContractSigner $signer,
    ) {}

    public function index(): View
    {
        $vendor = $this->workspace->vendor();
        $agreement = $this->gates->sellerAgreement();
        $categories = Category::query()->where('requires_contract', true)->orderBy('name_en')->get()->map(function (Category $category) use ($vendor): array {
            $resolved = $this->gates->categoryContract($category);

            return [
                'category' => $category,
                'resolved' => $resolved,
                'signed' => $resolved !== null && $this->signer->hasCurrent($resolved->version, SignerType::Vendor, $vendor->id, $resolved->subjectType, $resolved->subjectId),
            ];
        });
        $collaboration = $this->resolver->collaboration($this->countryContext->current(), ['influencer', 'affiliate', 'broker'])->map(fn (ContractVersion $version): array => [
            'version' => $version,
            'signed' => $this->signer->hasCurrent($version, SignerType::Vendor, $vendor->id),
        ]);

        return view('panels.partner.contracts.index', [
            'vendor' => $vendor,
            'agreement' => $agreement,
            'agreementSigned' => $this->gates->sellerAgreementAccepted($vendor),
            'categories' => $categories,
            'collaboration' => $collaboration,
            'signatures' => ContractSignature::query()->where('signer_type', SignerType::Vendor)->where('signer_id', $vendor->id)->latest('id')->limit(50)->get(),
        ]);
    }

    public function show(Request $request, int $version, ContractRenderer $renderer): View
    {
        $version = $this->signableVersion($version);

        return view('panels.partner.contracts.show', [
            'version' => $version,
            'text' => $renderer->text($version, app()->getLocale(), ['store_name' => $this->workspace->vendor()->store_name]),
            'categoryId' => $request->integer('category') ?: null,
        ]);
    }

    public function sign(Request $request, int $version): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $version = $this->signableVersion($version);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'accept' => ['accepted'],
            'category_id' => ['nullable', 'integer'],
        ]);
        [$subjectType, $subjectId] = [null, null];

        if ($version->template->kind === ContractKind::SellerCategory) {
            $category = Category::query()->findOrFail($data['category_id'] ?? 0);
            $resolved = $this->gates->categoryContract($category);
            abort_if($resolved === null || $resolved->version->id !== $version->id, 404);
            [$subjectType, $subjectId] = [$resolved->subjectType, $resolved->subjectId];
        }

        $this->signer->sign($version, SignerType::Vendor, $vendor->id, $request->user()->id, $data['full_name'], SignatureMethod::Click, $request->ip(), $request->userAgent(), $subjectType, $subjectId, app()->getLocale(), ['store_name' => $vendor->store_name]);

        if ($version->template->kind === ContractKind::SellerPlatform) {
            $vendor->forceFill(['seller_agreement_accepted_at' => now()])->save();
        }

        return redirect()->route('partner.contracts')->with('status', __('Signed. A copy is kept under Signed contracts.'));
    }

    public function download(int $signature): StreamedResponse
    {
        $signature = ContractSignature::query()->where('signer_type', SignerType::Vendor)->where('signer_id', $this->workspace->vendor()->id)->findOrFail($signature);

        return Storage::disk(ContractRenderer::DISK)->download($signature->frozen_path, "contract-{$signature->kind->value}-v{$signature->version}.".pathinfo($signature->frozen_path, PATHINFO_EXTENSION));
    }

    /**
     * Published seller-side versions of this country (or legacy) only.
     */
    private function signableVersion(int $id): ContractVersion
    {
        $version = ContractVersion::query()->with('template')->where('status', ContractVersionStatus::Published)->findOrFail($id);
        $countryId = $version->template->country_id;

        abort_unless(in_array($version->template->kind, [ContractKind::SellerPlatform, ContractKind::SellerCategory, ContractKind::SellerMarketer], true), 404);
        abort_unless($countryId === null || $countryId === $this->countryContext->current()->id, 404);

        return $version;
    }
}
