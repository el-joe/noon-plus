<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use Carbon\CarbonImmutable;

/**
 * "FBN inbound reminder" (MASTER_PLAN §11, weekly): plans submitted more
 * than `fbn.inbound_reminder_days` ago and still not shipped remind the
 * seller, at most once a week per plan.
 */
class InboundReminders
{
    public function __construct(private FbnSettings $settings) {}

    public function send(CarbonImmutable $now): int
    {
        $sent = 0;
        $due = InboundShipment::query()->where('status', InboundStatus::Submitted)
            ->where('submitted_at', '<=', $now->subDays($this->settings->int('inbound_reminder_days')))
            ->where(fn ($query) => $query->whereNull('reminded_at')->orWhere('reminded_at', '<=', $now->subDays(7)))
            ->get();

        foreach ($due as $shipment) {
            NotifySellerOfStateChange::notifyVendor($shipment->vendor_id, 'fbn.inbound_reminder',
                __('Inbound :number is waiting to be shipped', ['number' => $shipment->number]),
                __('Ship it to the warehouse and add the tracking number, or cancel the plan.'),
                '/inventory/inbound/'.$shipment->id);
            app(PartnerEmails::class)->toVendor($shipment->vendor_id, 'fbn.inbound_reminder', [
                'inbound_number' => $shipment->number,
                'status' => EmailValue::t(ucfirst(str_replace('_', ' ', $shipment->status->value))),
            ], '/inventory/inbound/'.$shipment->id, ['entity' => $shipment, 'key' => $now->toDateString()]);
            $shipment->forceFill(['reminded_at' => $now])->save();
            $sent++;
        }

        return $sent;
    }
}
