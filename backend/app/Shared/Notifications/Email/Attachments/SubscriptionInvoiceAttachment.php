<?php

namespace App\Shared\Notifications\Email\Attachments;

use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Support\SubscriptionInvoiceDocument;

/**
 * A vendor subscription invoice PDF (`invoice` = invoice id).
 */
class SubscriptionInvoiceAttachment implements EmailAttachment
{
    public function __construct(private SubscriptionInvoiceDocument $documents) {}

    public function build(array $arguments): ?array
    {
        $invoice = VendorSubscriptionInvoice::query()->find($arguments['invoice'] ?? null);

        return $invoice === null ? null : ['data' => (string) $this->documents->pdf($invoice)->getContent(), 'name' => $invoice->number.'.pdf', 'mime' => 'application/pdf'];
    }
}
