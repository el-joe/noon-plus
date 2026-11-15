<?php

namespace App\Domains\Contracts\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Exceptions\ContractNotConfigured;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * Every place a signature is required (ADR 8 "applies to every
 * registration"): the Seller Agreement, individual category/product
 * contracts (ListingContractGate), the marketer onboarding contract
 * (FR-MKT-1), collaboration contracts for campaigns, and the customer
 * checkout gate (FR-CHK-4: marketer contract required for customers, and
 * customer purchase contracts assigned to a category).
 */
class ContractGates
{
    public function __construct(
        private ContractResolver $resolver,
        private ContractSigner $signer,
        private CountryContext $countryContext,
    ) {}

    /**
     * With a published Seller Agreement in the country, the store must hold
     * a signature of the current version (click or continued use). A country
     * without one keeps the registration acceptance stamp.
     */
    public function sellerAgreementAccepted(Vendor $vendor): bool
    {
        $version = $this->resolver->current(ContractKind::SellerPlatform, $this->countryContext->current());

        if ($version === null) {
            return $vendor->seller_agreement_accepted_at !== null;
        }

        return $this->signer->hasCurrent($version, SignerType::Vendor, $vendor->id);
    }

    public function sellerAgreement(): ?ContractVersion
    {
        return $this->resolver->current(ContractKind::SellerPlatform, $this->countryContext->current());
    }

    /**
     * ListingContractGate: the resolved individual contract is signed by the
     * store for its subject. Not configured = not signed (safe default).
     */
    public function categoryContractSigned(Vendor $vendor, Category $category, ?Product $product = null): bool
    {
        $resolved = $this->categoryContract($category, $product);

        return $resolved !== null && $this->signer->hasCurrent($resolved->version, SignerType::Vendor, $vendor->id, $resolved->subjectType, $resolved->subjectId);
    }

    public function categoryContract(Category $category, ?Product $product = null): ?ResolvedContract
    {
        try {
            return $this->resolver->forCategory(ContractKind::SellerCategory, $this->countryContext->current(), $category, $product);
        } catch (ContractNotConfigured) {
            return null;
        }
    }

    /**
     * FR-MKT-1 (contract part): no onboarding contract published = nothing to accept.
     */
    public function marketerContractAccepted(MarketerProfile $profile): bool
    {
        $version = $this->resolver->current(ContractKind::MarketerOnboarding, $this->countryContext->current());

        return $version === null || $this->signer->hasCurrent($version, SignerType::Marketer, $profile->id);
    }

    /**
     * Collaboration contracts the store still has to sign for these marketer types.
     *
     * @param  array<int, string>  $jobTypes
     * @return Collection<int, ContractVersion>
     */
    public function missingCollaboration(Vendor $vendor, array $jobTypes): Collection
    {
        return $this->resolver->collaboration($this->countryContext->current(), $jobTypes)
            ->reject(fn (ContractVersion $version): bool => $this->signer->hasCurrent($version, SignerType::Vendor, $vendor->id))
            ->values();
    }

    /**
     * FR-CHK-4: contracts the customer must accept before checking out
     * lines credited to these marketers or from these categories.
     *
     * @param  array<int, int>  $marketerProfileIds
     * @param  array<int, Category>  $categories
     * @return Collection<int, ContractVersion>
     */
    public function missingForCustomer(int $customerUserId, array $marketerProfileIds, array $categories): Collection
    {
        $country = $this->countryContext->current();
        $required = collect();

        if ($marketerProfileIds !== []) {
            $marketerContract = $this->resolver->current(ContractKind::MarketerOnboarding, $country);

            if ($marketerContract !== null && $marketerContract->template->required_for_customers) {
                $required->push($marketerContract);
            }
        }

        foreach ($categories as $category) {
            $purchase = $this->resolver->forCategory(ContractKind::CustomerPurchase, $country, $category);

            if ($purchase !== null) {
                $required->push($purchase->version);
            }
        }

        return $required->unique('id')
            ->reject(fn (ContractVersion $version): bool => $this->signer->hasCurrent($version, SignerType::Customer, $customerUserId))
            ->values();
    }
}
