<?php

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Exceptions\InvalidWebhookSignature;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Support\WebhookProcessor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/v1/{market}/payments/webhooks/{gateway}` — gateway
 * notifications for one country. A bad signature is 401 and stores
 * nothing; a duplicate is 200 so the provider stops retrying.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, WebhookProcessor $processor): JsonResponse
    {
        $method = PaymentMethod::tryFrom($gateway);

        if ($method === null || ! $method->isGateway()) {
            abort(404);
        }

        try {
            $result = $processor->handle($method, $request);
        } catch (InvalidWebhookSignature $invalid) {
            Log::warning('Rejected payment webhook', ['gateway' => $gateway, 'reason' => $invalid->getMessage()]);

            return ApiResponse::error('invalid_signature', __('Invalid signature.'), 401);
        } catch (PaymentMethodUnavailable) {
            abort(404);
        }

        return ApiResponse::success(['result' => $result]);
    }
}
