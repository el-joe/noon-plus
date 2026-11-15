<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Content\Notifications\TemplatedNotification;
use App\Domains\Content\Support\NotificationTemplates;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Domains\Delivery\Models\Shipment;
use App\Models\User;
use App\Shared\Money\MoneyFormatter;

/**
 * Customer messages of the delivery flow, rendered from the country's
 * notification templates in the customer's language (ADR 10). The
 * out-for-delivery message carries the delivery code (ADR 15).
 */
class DeliveryNotices
{
    public function __construct(
        private NotificationTemplates $templates,
        private CountryContext $countryContext,
        private MoneyFormatter $formatter,
    ) {}

    public function assignment(string $event, DeliveryAssignment $assignment): void
    {
        $shipment = $assignment->shipment()->firstOrFail();
        $agent = $assignment->agent()->first();

        $this->send($event, $shipment, [
            'delivery_code' => $assignment->delivery_code,
            'agent_name' => $agent?->name,
            'reason' => $assignment->failure_reason === null ? null : str_replace('_', ' ', $assignment->failure_reason),
            'cod_amount' => $assignment->cod_amount === null ? '' : ' '.__('Please have :amount ready.', ['amount' => $this->formatter->format((string) $assignment->cod_amount, $assignment->currency)]),
        ]);
    }

    public function shipment(string $event, Shipment $shipment): void
    {
        $this->send($event, $shipment, ['date' => $shipment->rescheduled_for?->toDateString()]);
    }

    /**
     * @param  array<string, string|null>  $variables
     */
    private function send(string $event, Shipment $shipment, array $variables): void
    {
        $subOrder = $shipment->subOrder()->firstOrFail();
        $order = $subOrder->order()->firstOrFail();
        $customer = User::query()->find($order->customer_user_id);

        if ($customer === null || $customer->email === null) {
            return;
        }

        $locale = in_array($customer->preferred_locale, ['en', 'ar'], true) ? $customer->preferred_locale : 'en';
        $text = $this->templates->render($event, 'mail', $locale, $variables + [
            'customer_name' => $customer->name,
            'order_number' => $order->number,
            'shipment_number' => $subOrder->number,
        ], $this->countryContext->current());

        if ($text !== null) {
            $customer->notify(new TemplatedNotification($event, (string) $text['subject'], $text['body']));
        }
    }
}
