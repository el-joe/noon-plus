<?php

namespace App\Shared\Notifications\Email\Attachments;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\InvoicePdf;
use App\Models\User;

/**
 * The customer's tax invoice PDF for a whole order (`order` = order number).
 */
class OrderInvoiceAttachment implements EmailAttachment
{
    public function __construct(private InvoicePdf $invoices, private CountryContext $countryContext) {}

    public function build(array $arguments): ?array
    {
        $order = Order::query()->where('number', $arguments['order'] ?? null)->first();
        $customer = $order === null ? null : User::query()->find($order->customer_user_id);

        if ($order === null || $customer === null || ! InvoicePdf::isReady($order)) {
            return null;
        }

        return ['data' => $this->invoices->forOrder($order, $this->countryContext->current(), $customer), 'name' => 'invoice-'.$order->number.'.pdf', 'mime' => 'application/pdf'];
    }
}
