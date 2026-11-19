<?php

namespace App\Domains\Listings\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\Listings\Exceptions\ListingTransitionRefused;
use App\Domains\Vendors\Models\Vendor;

/**
 * Gates a seller must clear before creating a listing or sending it to review
 * (SRS §5.2 FR-LST-1, §13; lifecycle catalogue §1.1):
 *
 * 1. the store is active;
 * 2. the Seller Agreement is accepted (the current published version is
 *    signed; without one in the country, the registration stamp
 *    `vendors.seller_agreement_accepted_at` — §13.4);
 * 3. ListingContractGate — a category (or a parent) with `requires_contract`
 *    needs the seller's signed category contract;
 * 4. ListingCertificationGate — a category (or a parent) with
 *    `requires_certification` needs an approved product certification.
 *
 * {@see hasSignedCategoryContract()} asks the contract engine (WP5,
 * ContractResolver §13.5: product → nearest category → country default; a
 * category without a configured contract cannot be listed in) and
 * {@see hasApprovedCertification()} the documents module (§13.7).
 */
class ListingSubmissionGates
{
    /**
     * Checklist for the create-listing screen, in display order.
     *
     * @return array<string, array{label: string, passed: bool, required: bool}>
     */
    public function checklist(Vendor $vendor, ?Category $category = null): array
    {
        $needsContract = $category !== null && $this->categoryRequires($category, 'requires_contract');
        $needsCertificate = $category !== null && $this->categoryRequires($category, 'requires_certification');

        return [
            'active' => ['label' => __('Store active'), 'passed' => $vendor->isActive(), 'required' => true],
            ...$this->subscriptionGate($vendor),
            'agreement' => ['label' => __('Seller Agreement accepted'), 'passed' => $vendor->hasAcceptedSellerAgreement(), 'required' => true],
            'category_contract' => [
                'label' => __('Category contract signed'),
                'passed' => ! $needsContract || $this->hasSignedCategoryContract($vendor, $category),
                'required' => $needsContract,
            ],
            'certification' => [
                'label' => __('Product certification approved'),
                'passed' => ! $needsCertificate || $this->hasApprovedCertification($vendor, $category),
                'required' => $needsCertificate,
            ],
        ];
    }

    /**
     * Refuse with the first gate that is not cleared.
     */
    public function assertPasses(Vendor $vendor, ?Category $category = null): void
    {
        $messages = [
            'active' => __('Your store is not active, so you cannot list products yet.'),
            'agreement' => __('Accept the Seller Agreement before listing products.'),
            'subscription' => app(VendorSubscriptions::class)->isRestricted($vendor->id)
                ? __('Your subscription is past due or expired. Pay the open invoice or subscribe again to submit listings.')
                : __('Your plan\'s active listing limit is reached. Upgrade the plan to submit more listings.'),
            'category_contract' => __('This category needs a signed category contract before you can list in it.'),
            'certification' => __('This category needs an approved product certification before you can list in it.'),
        ];

        foreach ($this->checklist($vendor, $category) as $gate => $check) {
            if (! $check['passed']) {
                throw ListingTransitionRefused::because($messages[$gate]);
            }
        }
    }

    /**
     * ListingContractGate (SRS §13.5): the seller signed the current
     * category/product contract resolved for this country.
     */
    public function hasSignedCategoryContract(Vendor $vendor, Category $category): bool
    {
        return app(ContractGates::class)->categoryContractSigned($vendor, $category);
    }

    /**
     * ListingCertificationGate (SRS §13.7): the seller holds an approved,
     * unexpired certification for this category or a parent.
     */
    public function hasApprovedCertification(Vendor $vendor, Category $category): bool
    {
        return app(Documents::class)->hasApprovedCertification($vendor->id, $category);
    }

    /**
     * FR-FIN-7 feature limits: only for a store that has (had) a vendor
     * plan — no listing submissions while the latest subscription is
     * past_due or expired, and at most the plan's active listings.
     *
     * @return array<string, array{label: string, passed: bool, required: bool}>
     */
    private function subscriptionGate(Vendor $vendor): array
    {
        if (! VendorSubscription::query()->where('vendor_id', $vendor->id)->exists()) {
            return [];
        }

        $subscriptions = app(VendorSubscriptions::class);

        return ['subscription' => [
            'label' => __('Subscription in good standing'),
            'passed' => ! $subscriptions->isRestricted($vendor->id) && ! $subscriptions->listingLimitReached($vendor->id),
            'required' => true,
        ]];
    }

    private function categoryRequires(Category $category, string $switch): bool
    {
        foreach ($category->lineage() as $ancestor) {
            if ($ancestor->{$switch}) {
                return true;
            }
        }

        return false;
    }
}
