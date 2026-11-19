<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Enums\InboundStatus;
use App\Domains\Fulfilment\Models\InboundLine;
use App\Domains\Fulfilment\Models\InboundShipment;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * FBN inbound shipments (ASN). The seller drafts a plan (warehouse, lines:
 * listing, units, cartons), submits it (it gets its reference and carton
 * labels), ships it (carrier, tracking); the warehouse marks it arrived,
 * receives each line (good / damaged units, bin — stock is put away as it
 * is counted), finishes receiving (short units = expected − counted) and
 * closes it. Every move goes through the {@see StateMachine} with history
 * and actor.
 */
class InboundShipments
{
    public function __construct(
        private StateMachine $machine,
        private WarehouseStockService $stock,
        private CountryContext $countryContext,
    ) {}

    /**
     * @param  array<int, array{listing_id: int, quantity: int, cartons?: int|null}>  $lines
     * @param  array{cartons?: int|null, expected_at?: string|null, notes?: string|null}  $details
     */
    public function draft(Vendor $vendor, int $warehouseId, array $lines, array $details, int $actorUserId): InboundShipment
    {
        $warehouse = Warehouse::query()->active()->find($warehouseId) ?? throw new TransitionRefused(__('Choose an active FBN warehouse.'));
        $merged = [];

        foreach ($lines as $line) {
            $id = (int) $line['listing_id'];
            $merged[$id]['quantity'] = ($merged[$id]['quantity'] ?? 0) + (int) $line['quantity'];
            $merged[$id]['cartons'] = ($merged[$id]['cartons'] ?? 0) + max(1, (int) ($line['cartons'] ?? 1));
        }

        if ($merged === []) {
            throw new TransitionRefused(__('Add at least one product to the inbound plan.'));
        }

        $listings = Listing::query()->where('vendor_id', $vendor->id)->whereIn('id', array_keys($merged))->with('variant')->get()->keyBy('id');

        foreach (array_keys($merged) as $id) {
            $listing = $listings->get($id) ?? throw new TransitionRefused(__('One of the products is not in your store.'));

            if ($listing->fulfilment !== 'fbn') {
                throw new TransitionRefused(__(':sku is not enrolled in Fulfilled by Nawy yet.', ['sku' => $listing->variant->sku]));
            }

            if ($listing->status === ListingStatus::Archived) {
                throw new TransitionRefused(__('An archived listing cannot be changed.'));
            }
        }

        return DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $warehouse, $merged, $details, $actorUserId): InboundShipment {
            $shipment = InboundShipment::query()->create([
                'vendor_id' => $vendor->id,
                'warehouse_id' => $warehouse->id,
                'cartons' => max(1, (int) ($details['cartons'] ?? array_sum(array_column($merged, 'cartons')))),
                'expected_at' => $details['expected_at'] ?? null,
                'notes' => $details['notes'] ?? null,
            ]);

            foreach ($merged as $listingId => $line) {
                $shipment->lines()->create(['listing_id' => $listingId, 'quantity_expected' => $line['quantity'], 'cartons' => $line['cartons']]);
            }

            $this->machine->created($shipment, ActorType::Vendor, $actorUserId);

            return $shipment;
        });
    }

    public function submit(InboundShipment $shipment, int $actorUserId): InboundShipment
    {
        if (! $shipment->lines()->exists()) {
            throw new TransitionRefused(__('Add at least one product to the inbound plan.'));
        }

        $number = sprintf('FBN-IN-%s-%06d', strtoupper($this->countryContext->current()->iso2), $shipment->id);

        return $this->machine->move($shipment, InboundStatus::Submitted, ActorType::Vendor, $actorUserId, null, ['number' => $number, 'submitted_at' => now()]);
    }

    public function markShipped(InboundShipment $shipment, ?string $carrier, ?string $tracking, int $actorUserId): InboundShipment
    {
        return $this->machine->move($shipment, InboundStatus::InTransit, ActorType::Vendor, $actorUserId, null, [
            'carrier_name' => $carrier, 'tracking_number' => $tracking, 'shipped_at' => now(),
        ]);
    }

    public function markArrived(InboundShipment $shipment, int $actorUserId): InboundShipment
    {
        return $this->machine->move($shipment, InboundStatus::Arrived, ActorType::Admin, $actorUserId, null, ['arrived_at' => now()]);
    }

    public function startReceiving(InboundShipment $shipment, int $actorUserId): InboundShipment
    {
        return $this->machine->move($shipment, InboundStatus::Receiving, ActorType::Admin, $actorUserId);
    }

    /**
     * Count units of one line in (good go on the shelf and on sale, damaged
     * are kept apart). Counted units never exceed the expected quantity.
     */
    public function receiveLine(InboundLine $line, int $good, int $damaged, ?string $binCode, int $actorUserId): InboundLine
    {
        if ($good < 0 || $damaged < 0 || $good + $damaged === 0) {
            throw new TransitionRefused(__('Enter the units counted.'));
        }

        return DB::connection($line->getConnectionName())->transaction(function () use ($line, $good, $damaged, $binCode, $actorUserId): InboundLine {
            $shipment = InboundShipment::query()->lockForUpdate()->findOrFail($line->fbn_inbound_shipment_id);

            if ($shipment->status !== InboundStatus::Receiving) {
                throw new TransitionRefused(__('Start receiving the shipment first.'));
            }

            $line = InboundLine::query()->lockForUpdate()->findOrFail($line->id);

            if ($line->quantity_received + $line->quantity_damaged + $good + $damaged > $line->quantity_expected) {
                throw new TransitionRefused(__('Only :count more units of this line are expected.', ['count' => $line->shortQuantity()]));
            }

            $line->forceFill([
                'quantity_received' => $line->quantity_received + $good,
                'quantity_damaged' => $line->quantity_damaged + $damaged,
                'bin_code' => ($binCode !== null && $binCode !== '') ? $binCode : $line->bin_code,
            ])->save();

            $this->stock->receive($shipment->warehouse()->firstOrFail(), $line->listing()->with('variant')->firstOrFail(), $good, $damaged, $binCode, 'fbn_inbound_shipment', $shipment->id, $actorUserId);

            return $line;
        });
    }

    public function finishReceiving(InboundShipment $shipment, int $actorUserId): InboundShipment
    {
        $lines = $shipment->lines()->get();
        $short = $lines->sum(fn (InboundLine $line): int => $line->shortQuantity());
        $summary = [
            'received' => (int) $lines->sum('quantity_received'),
            'damaged' => (int) $lines->sum('quantity_damaged'),
            'short' => $short,
        ];

        $moved = $this->machine->move($shipment, InboundStatus::Received, ActorType::Admin, $actorUserId, $short > 0 ? "{$short} units short" : null, ['received_at' => now()], $summary);

        NotifySellerOfStateChange::notifyVendor($shipment->vendor_id, 'fbn.inbound_received',
            __('Inbound :number received', ['number' => $shipment->number]),
            __(':received units on sale, :damaged damaged, :short short.', $summary),
            '/inventory/inbound/'.$shipment->id);
        app(PartnerEmails::class)->toVendor($shipment->vendor_id, $short > 0 || $summary['damaged'] > 0 ? 'fbn.inbound_discrepancy' : 'fbn.inbound_received', [
            'inbound_number' => $shipment->number,
            'received' => (string) $summary['received'],
            'short' => (string) $summary['short'],
            'damaged' => (string) $summary['damaged'],
        ], '/inventory/inbound/'.$shipment->id, ['entity' => $shipment]);

        return $moved;
    }

    public function close(InboundShipment $shipment, int $actorUserId): InboundShipment
    {
        return $this->machine->move($shipment, InboundStatus::Closed, ActorType::Admin, $actorUserId, null, ['closed_at' => now()]);
    }

    public function cancel(InboundShipment $shipment, ActorType $actorType, int $actorUserId, ?string $reason = null): InboundShipment
    {
        return $this->machine->move($shipment, InboundStatus::Cancelled, $actorType, $actorUserId, $reason);
    }
}
