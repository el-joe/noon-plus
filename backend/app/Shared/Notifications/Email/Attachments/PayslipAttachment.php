<?php

namespace App\Shared\Notifications\Email\Attachments;

use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Support\PayslipDocument;

/**
 * A delivery agent's payslip PDF (`payslip` = payslip id).
 */
class PayslipAttachment implements EmailAttachment
{
    public function __construct(private PayslipDocument $documents) {}

    public function build(array $arguments): ?array
    {
        $payslip = DeliveryPayslip::query()->find($arguments['payslip'] ?? null);

        return $payslip === null ? null : ['data' => (string) $this->documents->pdf($payslip)->getContent(), 'name' => 'payslip-'.$payslip->number.'.pdf', 'mime' => 'application/pdf'];
    }
}
