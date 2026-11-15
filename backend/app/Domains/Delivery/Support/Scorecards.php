<?php

namespace App\Domains\Delivery\Support;

use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Agent and carrier scorecards (SRS §4.1.11 "Carrier Scorecard"; SLA
 * breaches from CheckSlaBreachJob). Over a period of finished attempts
 * (delivered or failed): success rate, on-time rate (delivered before
 * the SLA), failed attempts, average minutes from pickup to delivery, SLA
 * breaches; carriers add claims. Score = 60 % success + 40 % on time,
 * minus 5 points per approved / compensated claim (proposed weights).
 */
class Scorecards
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function agents(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $assignments = $this->finished($from, $to)->groupBy('delivery_agent_id');

        return DeliveryAgent::query()->orderBy('name')->get()->map(fn (DeliveryAgent $agent): array => ['agent' => $agent] + $this->metrics($assignments->get($agent->id, collect())));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function carriers(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $assignments = $this->finished($from, $to)->groupBy('carrier_id');
        $claims = CarrierClaim::query()->whereBetween('created_at', [$from, $to])->get()->groupBy('carrier_id');

        return Carrier::query()->orderBy('name')->get()->map(function (Carrier $carrier) use ($assignments, $claims): array {
            $metrics = $this->metrics($assignments->get($carrier->id, collect()));
            $carrierClaims = $claims->get($carrier->id, collect());
            $upheld = $carrierClaims->whereIn('status', [ClaimStatus::Approved, ClaimStatus::Compensated])->count();
            $metrics['claims'] = $carrierClaims->count();
            $metrics['claims_upheld'] = $upheld;
            $metrics['score'] = $metrics['score'] === null ? null : max(0, round($metrics['score'] - 5 * $upheld, 1));

            return ['carrier' => $carrier] + $metrics;
        });
    }

    /**
     * @param  Collection<int, DeliveryAssignment>  $assignments
     * @return array{attempts: int, delivered: int, failed: int, success_rate: float|null, on_time_rate: float|null, avg_minutes: float|null, sla_breaches: int, score: float|null}
     */
    public function metrics(Collection $assignments): array
    {
        $delivered = $assignments->where('status', AssignmentStatus::Delivered);
        $attempts = $assignments->count();
        $onTime = $delivered->filter(fn (DeliveryAssignment $assignment): bool => $assignment->sla_due_at === null || $assignment->delivered_at->lessThanOrEqualTo($assignment->sla_due_at))->count();
        $durations = $delivered->filter(fn (DeliveryAssignment $assignment): bool => $assignment->picked_up_at !== null)
            ->map(fn (DeliveryAssignment $assignment): float => $assignment->picked_up_at->diffInMinutes($assignment->delivered_at));
        $successRate = $attempts === 0 ? null : round(100 * $delivered->count() / $attempts, 1);
        $onTimeRate = $delivered->isEmpty() ? null : round(100 * $onTime / $delivered->count(), 1);

        return [
            'attempts' => $attempts,
            'delivered' => $delivered->count(),
            'failed' => $assignments->where('status', AssignmentStatus::Failed)->count(),
            'success_rate' => $successRate,
            'on_time_rate' => $onTimeRate,
            'avg_minutes' => $durations->isEmpty() ? null : round($durations->avg(), 1),
            'sla_breaches' => $assignments->whereNotNull('sla_breached_at')->count(),
            'score' => $successRate === null ? null : round(0.6 * $successRate + 0.4 * ($onTimeRate ?? 0), 1),
        ];
    }

    /**
     * @return Collection<int, DeliveryAssignment>
     */
    private function finished(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DeliveryAssignment::query()
            ->whereIn('status', [AssignmentStatus::Delivered, AssignmentStatus::Failed])
            ->where(fn ($query) => $query->whereBetween('delivered_at', [$from, $to])->orWhereBetween('failed_at', [$from, $to]))
            ->get();
    }
}
