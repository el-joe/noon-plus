<?php

namespace App\Domains\FlashSales\Support;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Enums\MarketerBonusType;
use App\Domains\FlashSales\Exceptions\FlashSaleTransitionRefused;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft flash-sale event. Admins type every time in the country's
 * local time; it is stored in UTC (ADR 14). The per-customer maximum
 * defaults to the `flash_sale.max_qty_per_customer` setting.
 */
class CreateFlashSaleEvent
{
    public function __construct(
        private CountryContext $countryContext,
        private CountryClock $clock,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array{name: string, display_name?: string|null, starts_at: string, ends_at: string, submission_starts_at?: string|null, submission_ends_at?: string|null, max_qty_per_customer?: int|null, marketer_bonus_type?: MarketerBonusType|null, marketer_bonus_value?: string|null}  $data  local date-times
     */
    public function handle(array $data, ?int $createdBy = null): FlashSaleEvent
    {
        $country = $this->countryContext->current();
        $toUtc = fn (?string $local) => $local === null ? null : $this->clock->localToUtc($country, $local);

        $startsAt = $toUtc($data['starts_at']);
        $endsAt = $toUtc($data['ends_at']);
        $submissionStartsAt = $toUtc($data['submission_starts_at'] ?? null);
        $submissionEndsAt = $toUtc($data['submission_ends_at'] ?? null);

        if (! $endsAt->greaterThan($startsAt)) {
            throw FlashSaleTransitionRefused::because('A flash sale must end after it starts.');
        }

        if ($submissionEndsAt !== null && $submissionEndsAt->greaterThan($startsAt)) {
            throw FlashSaleTransitionRefused::because('Submissions must close before the flash sale starts.');
        }

        if ($submissionStartsAt !== null && ! $submissionStartsAt->lessThan($submissionEndsAt ?? $startsAt)) {
            throw FlashSaleTransitionRefused::because('Submissions must open before they close.');
        }

        return DB::connection($this->countryContext->connectionName())->transaction(function () use ($data, $country, $startsAt, $endsAt, $submissionStartsAt, $submissionEndsAt, $createdBy): FlashSaleEvent {
            $event = FlashSaleEvent::query()->create([
                'name' => $data['name'],
                'display_name' => $data['display_name'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'submission_starts_at' => $submissionStartsAt,
                'submission_ends_at' => $submissionEndsAt,
                'max_qty_per_customer' => $data['max_qty_per_customer'] ?? (int) $this->settings->get('flash_sale.max_qty_per_customer', config('nawy.flash_sale.max_qty_per_customer'), $country),
                'marketer_bonus_type' => $data['marketer_bonus_type'] ?? null,
                'marketer_bonus_value' => $data['marketer_bonus_value'] ?? null,
                'created_by' => $createdBy,
            ]);
            $event->statusHistory()->create(['to_status' => FlashSaleEventStatus::Draft, 'actor_type' => ActorType::Admin, 'actor_user_id' => $createdBy]);

            return $event;
        });
    }
}
