<?php

namespace App\Domains\Travel\Support;

use App\Domains\Travel\Models\TravelDeparture;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Transactional seat allocation (MP §3 seat capacity locking, lifecycle
 * catalogue §5.3). The departure row is re-read FOR UPDATE and
 * `seats_reserved` (held + paid seats) only moves inside that lock, so two
 * customers can never take the last seat twice — whatever copy of the
 * departure the caller holds. Must run inside the caller's transaction.
 */
class SeatAllocator
{
    public function take(int $departureId, int $seats): TravelDeparture
    {
        $this->assertInTransaction();
        $departure = TravelDeparture::query()->lockForUpdate()->findOrFail($departureId);

        if ($departure->status !== 'open' || $departure->departs_on->isPast() || $departure->departs_on->isToday()) {
            throw new TransitionRefused(__('This departure is not open for booking.'));
        }

        if ($seats < 1 || $departure->seat_capacity - $departure->seats_reserved < $seats) {
            throw new TransitionRefused(__('Only :count seat(s) left on this departure.', ['count' => max(0, $departure->seat_capacity - $departure->seats_reserved)]));
        }

        $departure->forceFill(['seats_reserved' => $departure->seats_reserved + $seats])->save();

        return $departure;
    }

    public function release(int $departureId, int $seats): TravelDeparture
    {
        $this->assertInTransaction();
        $departure = TravelDeparture::query()->lockForUpdate()->findOrFail($departureId);
        $departure->forceFill(['seats_reserved' => max(0, $departure->seats_reserved - $seats)])->save();

        return $departure;
    }

    public function available(TravelDeparture $departure): int
    {
        return max(0, $departure->seat_capacity - $departure->seats_reserved);
    }

    private function assertInTransaction(): void
    {
        if (DB::connection((new TravelDeparture)->getConnectionName())->transactionLevel() === 0) {
            throw new \LogicException('Seat allocation must run inside a database transaction.');
        }
    }
}
