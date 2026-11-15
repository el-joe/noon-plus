<?php

namespace App\Domains\Contracts\Http\Controllers\Api;

use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Contracts\Support\ContractRenderer;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller Agreement click-wrap in the vendor ops app (QC-017, FEATURE_MATRIX
 * §11 "web and vendor app"): read the current version and, as the store
 * owner, sign it. Every other `ops/vendor` route is refused with
 * `seller_agreement_required` until then.
 */
class VendorAgreementApiController extends Controller
{
    public function __construct(private ContractGates $gates) {}

    public function show(Request $request, ContractRenderer $renderer): JsonResponse
    {
        $vendor = $this->vendor($request);
        $agreement = $this->gates->sellerAgreement();

        return ApiResponse::success([
            'required' => $agreement !== null,
            'signed' => $this->gates->sellerAgreementAccepted($vendor),
            'can_sign' => $vendor->owner_user_id === $request->user()->id,
            'version' => $agreement === null ? null : [
                'id' => $agreement->id,
                'title' => $agreement->template->name ?? null,
                'text' => $renderer->text($agreement, app()->getLocale(), ['store_name' => $vendor->store_name]),
            ],
        ]);
    }

    public function sign(Request $request, ContractSigner $signer): JsonResponse
    {
        $vendor = $this->vendor($request);
        $data = $request->validate(['version_id' => ['required', 'integer'], 'full_name' => ['required', 'string', 'max:160'], 'accept' => ['accepted']]);
        $agreement = $this->gates->sellerAgreement();

        if ($vendor->owner_user_id !== $request->user()->id) {
            return ApiResponse::error('owner_only', __('Only the store owner can sign the Seller Agreement.'), 403);
        }

        if ($agreement === null || $agreement->id !== (int) $data['version_id']) {
            return ApiResponse::error('version_outdated', __('This agreement version is no longer current. Reload it and sign again.'), 409);
        }

        $signer->sign($agreement, SignerType::Vendor, $vendor->id, $request->user()->id, $data['full_name'], SignatureMethod::Click, $request->ip(), $request->userAgent(), locale: app()->getLocale(), variables: ['store_name' => $vendor->store_name]);
        $vendor->forceFill(['seller_agreement_accepted_at' => now()])->save();

        return ApiResponse::success(['signed' => true]);
    }

    private function vendor(Request $request): Vendor
    {
        return $request->attributes->get('ops_workspace');
    }
}
