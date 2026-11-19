<?php

namespace App\Domains\Fulfilment\Support;

use App\Domains\Campaigns\Support\CampaignLedger;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Fulfilment\Models\StorageInvoice;
use App\Domains\Fulfilment\Models\StorageSnapshot;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Panels\Support\Panel;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FBN storage (MASTER_PLAN §11 scheduler):
 *
 * - 01:00 "FBN overage" — {@see self::snapshot()}: one row per warehouse ×
 *   listing with units, damaged units, long-term / unsellable units and the
 *   unit volume; warehouses over their capacity get `over_capacity_since`.
 * - 01:15 "then flag" — {@see self::flag()}: units stored longer than
 *   `long_term_days` and damaged units older than `unsellable_days` are
 *   flagged (seller notified once); the next snapshots count them.
 * - Monthly "FBN storage invoices" — {@see self::invoice()}: per seller, the
 *   month's average cubic feet (Σ daily volume ÷ days in month) × the
 *   storage, long-term and unsellable rates, charged once on the store's
 *   next statement (Dr vendor / Cr fee revenue).
 *
 * Every step is idempotent (unique day / month keys).
 */
class StorageBilling
{
    public function __construct(
        private FbnFees $fees,
        private FbnSettings $settings,
        private CampaignLedger $ledger,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    /**
     * @return array{rows: int, over_capacity: int}
     */
    public function snapshot(CarbonImmutable $day): array
    {
        $rows = 0;
        $volumes = [];

        WarehouseStock::query()->where(fn ($query) => $query->where('on_hand', '>', 0)->orWhere('damaged', '>', 0))
            ->with('listing.variant')->chunkById(500, function ($stock) use ($day, &$rows, &$volumes): void {
                foreach ($stock as $row) {
                    $unitCubicFeet = $this->fees->unitCubicFeet($row->listing->variant);
                    StorageSnapshot::query()->updateOrCreate(
                        ['snapshot_on' => $day->toDateString(), 'warehouse_id' => $row->warehouse_id, 'listing_id' => $row->listing_id],
                        [
                            'vendor_id' => $row->listing->vendor_id,
                            'units' => $row->on_hand,
                            'damaged_units' => $row->damaged,
                            'long_term_units' => $row->long_term_flagged_at !== null ? $row->on_hand : 0,
                            'unsellable_units' => $row->unsellable_flagged_at !== null ? $row->damaged : 0,
                            'unit_cubic_feet' => $unitCubicFeet,
                        ],
                    );
                    $volumes[$row->warehouse_id] = BigDecimal::of($volumes[$row->warehouse_id] ?? '0')->plus(BigDecimal::of($unitCubicFeet)->multipliedBy($row->on_hand + $row->damaged));
                    $rows++;
                }
            });

        $over = 0;

        foreach (Warehouse::query()->get() as $warehouse) {
            $used = BigDecimal::of((string) ($volumes[$warehouse->id] ?? '0'));
            $isOver = $warehouse->capacity_cubic_feet !== null && $used->isGreaterThan((string) $warehouse->capacity_cubic_feet);
            $warehouse->forceFill(['over_capacity_since' => $isOver ? ($warehouse->over_capacity_since ?? now()) : null])->save();
            $over += $isOver ? 1 : 0;
            $this->alertCapacity($warehouse, $used, $day);
        }

        return ['rows' => $rows, 'over_capacity' => $over];
    }

    /**
     * Ops alert when a warehouse uses at least the configured share of its
     * capacity — once per warehouse per day.
     */
    private function alertCapacity(Warehouse $warehouse, BigDecimal $used, CarbonImmutable $day): void
    {
        if ($warehouse->capacity_cubic_feet === null || BigDecimal::of((string) $warehouse->capacity_cubic_feet)->isLessThanOrEqualTo(0)) {
            return;
        }

        $percent = $used->multipliedBy(100)->dividedBy((string) $warehouse->capacity_cubic_feet, 1, RoundingMode::HalfUp);

        if ($percent->isLessThan((int) config('nawy.mail.reminders.warehouse_capacity_percent', 90))) {
            return;
        }

        app(Emails::class)->alertAdmins('admin.warehouse_capacity', [
            'warehouse' => trim($warehouse->code.' · '.$warehouse->name_en, ' ·'),
            'used_percent' => (string) $percent,
        ], ['entity' => $warehouse, 'key' => $day->toDateString(), 'url' => Panel::find('admin')->url('/inventory/warehouses')]);
    }

    /**
     * @return array{long_term: int, unsellable: int}
     */
    public function flag(CarbonImmutable $now): array
    {
        $longTerm = WarehouseStock::query()->whereNull('long_term_flagged_at')->where('on_hand', '>', 0)
            ->where('first_received_at', '<=', $now->subDays($this->settings->int('long_term_days')))->with('listing.variant')->get();
        $unsellable = WarehouseStock::query()->whereNull('unsellable_flagged_at')->where('damaged', '>', 0)
            ->where('damaged_since', '<=', $now->subDays($this->settings->int('unsellable_days')))->with('listing.variant')->get();

        foreach ($longTerm as $row) {
            $row->forceFill(['long_term_flagged_at' => $now])->save();
            NotifySellerOfStateChange::notifyVendor($row->listing->vendor_id, 'fbn.long_term',
                __('Long-term storage: :sku', ['sku' => $row->listing->variant->sku]),
                __(':count units have been in the warehouse over :days days; long-term storage fees apply until they sell or are removed.', ['count' => $row->on_hand, 'days' => $this->settings->int('long_term_days')]),
                '/inventory');
        }

        foreach ($unsellable as $row) {
            $row->forceFill(['unsellable_flagged_at' => $now])->save();
            NotifySellerOfStateChange::notifyVendor($row->listing->vendor_id, 'fbn.unsellable',
                __('Unsellable stock: :sku', ['sku' => $row->listing->variant->sku]),
                __(':count damaged units are stored; request a removal to stop unsellable storage fees.', ['count' => $row->damaged]),
                '/inventory/removals');
        }

        return ['long_term' => $longTerm->count(), 'unsellable' => $unsellable->count()];
    }

    /**
     * Invoice the month that contains `$monthDay` for every seller with
     * snapshots in it.
     *
     * @return int invoices created
     */
    public function invoice(CarbonImmutable $monthDay): int
    {
        $start = $monthDay->startOfMonth();
        $end = $monthDay->endOfMonth();
        $days = $start->daysInMonth;
        $currency = $this->countryContext->current()->currency;
        $created = 0;

        $vendorIds = StorageSnapshot::query()->whereBetween('snapshot_on', [$start->toDateString(), $end->toDateString()])->distinct()->pluck('vendor_id');

        foreach ($vendorIds as $vendorId) {
            if (StorageInvoice::query()->where('vendor_id', $vendorId)->whereDate('period_start', $start->toDateString())->exists()) {
                continue;
            }

            DB::connection(StorageInvoice::query()->getConnection()->getName())->transaction(function () use ($vendorId, $start, $end, $days, $currency, &$created): void {
                $totals = ['storage' => BigDecimal::zero(), 'long_term' => BigDecimal::zero(), 'unsellable' => BigDecimal::zero()];
                $perListing = [];

                foreach (StorageSnapshot::query()->where('vendor_id', $vendorId)->whereBetween('snapshot_on', [$start->toDateString(), $end->toDateString()])->cursor() as $snapshot) {
                    $unit = BigDecimal::of((string) $snapshot->unit_cubic_feet);
                    $storage = $unit->multipliedBy($snapshot->units + $snapshot->damaged_units);
                    $totals['storage'] = $totals['storage']->plus($storage);
                    $totals['long_term'] = $totals['long_term']->plus($unit->multipliedBy($snapshot->long_term_units));
                    $totals['unsellable'] = $totals['unsellable']->plus($unit->multipliedBy($snapshot->unsellable_units));
                    $perListing[$snapshot->listing_id] = BigDecimal::of($perListing[$snapshot->listing_id] ?? '0')->plus($storage);
                }

                $average = fn (BigDecimal $cubicFeetDays): string => (string) $cubicFeetDays->dividedBy($days, 4, RoundingMode::HalfUp);
                $storageFee = $this->fees->storage($average($totals['storage']), 'storage', $currency);
                $longTermFee = $this->fees->storage($average($totals['long_term']), 'long_term', $currency);
                $unsellableFee = $this->fees->storage($average($totals['unsellable']), 'unsellable', $currency);
                $total = $storageFee->plus($longTermFee)->plus($unsellableFee);

                $invoice = StorageInvoice::query()->create([
                    'vendor_id' => $vendorId,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'currency' => $currency,
                    'storage_cubic_feet' => $average($totals['storage']),
                    'long_term_cubic_feet' => $average($totals['long_term']),
                    'unsellable_cubic_feet' => $average($totals['unsellable']),
                    'storage_fee' => $this->money->toStorage($storageFee),
                    'long_term_fee' => $this->money->toStorage($longTermFee),
                    'unsellable_fee' => $this->money->toStorage($unsellableFee),
                    'total' => $this->money->toStorage($total),
                    'lines' => collect($perListing)->map(fn (BigDecimal $cubicFeetDays, int $listingId): array => ['listing_id' => $listingId, 'cubic_feet' => $average($cubicFeetDays)])->values()->all(),
                    'charged_at' => now(),
                ]);
                $invoice->forceFill(['number' => sprintf('FBN-ST-%s-%06d', strtoupper($this->countryContext->current()->iso2), $invoice->id)])->save();

                $this->ledger->chargeVendor((int) $vendorId, $total, VendorBalanceItem::TYPE_FBN_STORAGE, $invoice, "fbn-storage:{$invoice->id}:charge",
                    "FBN storage {$start->format('Y-m')}", AccountRef::platform(LedgerAccountCode::FeeRevenue));
                app(PartnerEmails::class)->toVendor((int) $vendorId, 'fbn.storage_invoice', [
                    'invoice_number' => (string) $invoice->number,
                    'period' => $start->format('Y-m'),
                    'amount' => EmailValue::money($this->money->toStorage($total), $currency),
                ], '/fees', ['entity' => $invoice]);
                $created++;
            });
        }

        return $created;
    }
}
