<?php

namespace App\Domains\Contracts\Http;

use App\Domains\Contracts\Support\ContractGates;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Click-wrap on the partner web panel (SRS §13.4): while the store has not
 * signed the current Seller Agreement it is sent to Contracts; the vendor
 * ops app gets 403 `seller_agreement_required` (QC-017).
 */
class EnsureSellerAgreementSigned
{
    public function __construct(private VendorWorkspace $workspace, private ContractGates $gates) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->workspace->has() && $this->gates->sellerAgreement() !== null && ! $this->gates->sellerAgreementAccepted($this->workspace->vendor())) {
            if ($request->is('api/*')) {
                return ApiResponse::error('seller_agreement_required', __('Sign the updated Seller Agreement to continue.'), 403);
            }

            return redirect()->route('partner.contracts')->withErrors(['action' => __('Sign the updated Seller Agreement to continue.')]);
        }

        return $next($request);
    }
}
