<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An agent's earnings (Figma 108:8009; delivery panel and delivery app):
 * delivered stops and failed attempts per country day of the last 7 days
 * at the country's pay rates (`delivery.pay_per_stop`,
 * `delivery.pay_per_failed_attempt`; no per-stop rate = counts only, no
 * per-failed rate = 0). Amounts are storage strings in the country currency.
 */
class AgentEarnings
{
    public function __construct(private VerticalSettings $settings, private MoneyFactory $money, private CountryContext $countryContext) {}

    /**
     * @return array{days: Collection<int, array{day: CarbonImmutable, delivered: int, failed: int, earned: string|null}>, per_stop: mixed, per_failed: mixed, total: string|null, stops: int, failed: int, currency: string}
     */
    public function lastSevenDays(DeliveryAgent $agent): array
    {
        $country = $this->countryContext->current();
        $perStop = $this->settings->get('delivery.pay_per_stop');
        $perFailed = $this->settings->get('delivery.pay_per_failed_attempt');
        $today = CarbonImmutable::now($country->timezone)->startOfDay();
        $from = $today->subDays(6);
        $finished = DeliveryAssignment::query()->where('delivery_agent_id', $agent->id)
            ->whereIn('status', [AssignmentStatus::Delivered, AssignmentStatus::Failed])
            ->where('updated_at', '>=', $from->utc())->get();

        $days = collect(range(0, 6))->map(function (int $offset) use ($from, $finished, $country, $perStop, $perFailed): array {
            $day = $from->addDays($offset);
            $onDay = $finished->filter(fn (DeliveryAssignment $assignment): bool => ($assignment->delivered_at ?? $assignment->updated_at)->setTimezone($country->timezone)->isSameDay($day));
            $delivered = $onDay->where('status', AssignmentStatus::Delivered)->count();
            $failed = $onDay->where('status', AssignmentStatus::Failed)->count();
            $earned = null;

            if ($perStop !== null) {
                $earned = $this->money->of((string) $perStop, $country->currency)->multipliedBy($delivered)
                    ->plus($this->money->of((string) ($perFailed ?? '0'), $country->currency)->multipliedBy($failed));
            }

            return ['day' => $day, 'delivered' => $delivered, 'failed' => $failed, 'earned' => $earned === null ? null : $this->money->toStorage($earned)];
        });

        return [
            'days' => $days,
            'per_stop' => $perStop,
            'per_failed' => $perFailed,
            'total' => $perStop === null ? null : $this->money->toStorage($days->reduce(fn ($carry, array $day) => $carry->plus($this->money->of($day['earned'], $country->currency)), $this->money->zero($country->currency))),
            'stops' => $days->sum('delivered'),
            'failed' => $days->sum('failed'),
            'currency' => $country->currency,
        ];
    }
}
