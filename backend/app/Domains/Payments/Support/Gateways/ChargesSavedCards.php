<?php

namespace App\Domains\Payments\Support\Gateways;

use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\PaymentRequest;

/**
 * A gateway that can charge a saved card token without the cardholder
 * present (merchant-initiated, e.g. a vendor plan auto-renewal). Only the
 * gateway's token is sent — Nawy never holds card data. The answer is
 * final for the attempt: captured, or failed with the provider's reason
 * (a card that needs the holder, e.g. 3-D Secure, fails and the invoice
 * falls back to manual payment).
 */
interface ChargesSavedCards
{
    /**
     * @throws GatewayError when the provider cannot be reached
     */
    public function chargeSavedCard(PaymentRequest $request, SavedPaymentCard $card): GatewayOutcome;
}
