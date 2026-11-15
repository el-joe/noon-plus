<?php

namespace App\Domains\Payments\Http\Resources;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt for the customer. `next_action` tells the app what to
 * do: `redirect` (hosted page / 3DS) while pending with a URL, `wait` while
 * pending without one (3DS waiting screen polls the order), `retry` after a
 * failure (choose another method), `none` otherwise (COD, paid).
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nextAction = match (true) {
            $this->status === PaymentStatus::Pending && $this->redirect_url !== null => ['type' => 'redirect', 'url' => $this->redirect_url],
            $this->status === PaymentStatus::Pending && $this->method->isGateway() => ['type' => 'wait'],
            in_array($this->status, [PaymentStatus::Failed], true) => ['type' => 'retry'],
            default => ['type' => 'none'],
        };

        return [
            'reference' => $this->reference,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount' => MoneyResource::make((string) $this->amount),
            'refunded' => MoneyResource::make((string) $this->refunded_amount),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'failure' => $this->failure_code === null ? null : ['code' => $this->failure_code, 'message' => $this->failure_message],
            'next_action' => $nextAction,
        ];
    }
}
