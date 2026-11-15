<?php

namespace App\Domains\Orders\Support;

use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderStatusChange;
use App\Domains\Orders\Models\SubOrder;

/**
 * Steps for the "State timeline" component (Figma 90:2170 / 97:1164): every
 * recorded transition with its actor and time, then the steps still ahead on
 * the normal path, greyed out.
 */
class SubOrderTimeline
{
    private const HAPPY_PATH = [
        SubOrderStatus::Placed, SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed,
        SubOrderStatus::Shipped, SubOrderStatus::OutForDelivery, SubOrderStatus::Delivered, SubOrderStatus::Completed,
    ];

    /**
     * @return array<int, array{label: string, meta: string, state: string}>
     */
    public function steps(SubOrder $subOrder, string $timezone, string $zoneLabel): array
    {
        $history = $subOrder->statusHistory;
        $steps = $history->values()->map(fn (OrderStatusChange $change, int $index): array => [
            'label' => $this->label($change->to_status),
            'meta' => trim(ucfirst($change->actor_type->value).' · '.$change->created_at->setTimezone($timezone)->format('j M H:i').' '.$zoneLabel
                .($change->reason ? ' · '.$change->reason : '')),
            'state' => match (true) {
                $change->to_status === SubOrderStatus::Cancelled => 'stopped',
                $index === $history->count() - 1 && $subOrder->status->allowedNext() !== [] => 'current',
                default => 'done',
            },
        ])->all();

        $position = array_search($subOrder->status, self::HAPPY_PATH, true);

        if ($position !== false) {
            foreach (array_slice(self::HAPPY_PATH, $position + 1) as $upcoming) {
                $steps[] = ['label' => $this->label($upcoming), 'meta' => '', 'state' => 'pending'];
            }
        }

        return $steps;
    }

    public function label(SubOrderStatus $status): string
    {
        return match ($status) {
            SubOrderStatus::Shipped => __('Shipped (handed to carrier)'),
            default => str_replace('_', ' ', ucfirst($status->value)),
        };
    }
}
