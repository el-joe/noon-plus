<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Models\User;
use App\Shared\Http\StorefrontLinks;
use App\Shared\Localization\InLocale;
use App\Shared\Localization\Label;
use App\Shared\Money\MoneyFormatter;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;

/**
 * Customer messages of the delivery flow, sent as catalogue emails
 * ({@see Emails}) in the customer's language from the country's templates
 * (ADR 10). The out-for-delivery message carries the delivery code
 * (ADR 15) and also goes out by SMS.
 */
class DeliveryNotices
{
    public function __construct(
        private Emails $emails,
        private StorefrontLinks $storefront,
        private MoneyFormatter $formatter,
    ) {}

    public function assignment(string $event, DeliveryAssignment $assignment): void
    {
        $shipment = $assignment->shipment()->firstOrFail();
        $agent = $assignment->agent()->first();

        $this->send($event, $shipment, fn (User $customer): array => [
            'delivery_code' => $assignment->delivery_code,
            'agent_name' => $agent?->name,
            'reason' => $assignment->failure_reason === null ? null : EmailValue::t(Label::key($assignment->failure_reason)),
            'cod_amount' => $assignment->cod_amount === null ? '' : InLocale::run($customer->preferredLocale(), fn (): string => ' '.__('Please have :amount ready.', [
                'amount' => $this->formatter->format((string) $assignment->cod_amount, $assignment->currency),
            ])),
        ], $assignment);
    }

    public function shipment(string $event, Shipment $shipment): void
    {
        $this->send($event, $shipment, fn (): array => [
            'date' => $shipment->rescheduled_for === null ? null : EmailValue::date($shipment->rescheduled_for->toDateString().' 12:00:00'),
        ], $shipment, (string) $shipment->rescheduled_for?->toDateString());
    }

    /**
     * @param  callable(User): array<string, mixed>  $variables
     */
    private function send(string $event, Shipment $shipment, callable $variables, DeliveryAssignment|Shipment $entity, ?string $key = null): void
    {
        $subOrder = $shipment->subOrder()->firstOrFail();
        $order = $subOrder->order()->firstOrFail();
        $customer = User::query()->find($order->customer_user_id);

        if ($customer === null || blank($customer->email)) {
            return;
        }

        $this->emails->send($event, $customer, $variables($customer) + [
            'customer_name' => $customer->name,
            'order_number' => $order->number,
            'shipment_number' => $subOrder->number,
        ], array_filter([
            'entity' => $entity,
            'key' => $key,
            'url' => $this->storefront->to('account/orders/'.$order->number, [], $customer->preferredLocale()),
        ]));
    }
}
