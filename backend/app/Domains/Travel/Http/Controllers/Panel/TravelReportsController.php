<?php

namespace App\Domains\Travel\Http\Controllers\Panel;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelPackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Travel Partner → Reports (SRS §4.5): booking requests per month and
 * status, travellers and the confirmed booking value of the last six
 * months, and the best packages. No payment runs on Nawy (decision D8), so
 * there is no earnings page — the value is what the agency settles with
 * its customers.
 */
class TravelReportsController extends Controller
{
    public function __invoke(Request $request, CountryContext $countryContext): View
    {
        $agency = $request->attributes->get('agency');
        $from = now()->startOfMonth()->subMonths(5);
        $bookings = TravelBooking::query()->where('vendor_id', $agency->id)->where('created_at', '>=', $from)
            ->whereNotIn('status', [BookingStatus::Held, BookingStatus::HoldExpired])->get();
        $confirmed = [BookingStatus::Confirmed, BookingStatus::Completed];

        $months = collect(range(0, 5))->map(function (int $offset) use ($from, $bookings, $confirmed): array {
            $month = $from->copy()->addMonths($offset);
            $inMonth = $bookings->filter(fn (TravelBooking $booking): bool => $booking->created_at->isSameMonth($month));
            $won = $inMonth->filter(fn (TravelBooking $booking): bool => in_array($booking->status, $confirmed, true));

            return [
                'month' => $month,
                'requests' => $inMonth->count(),
                'confirmed' => $won->count(),
                'rejected_or_cancelled' => $inMonth->filter(fn (TravelBooking $booking): bool => in_array($booking->status, [BookingStatus::Rejected, BookingStatus::Cancelled], true))->count(),
                'travellers' => (int) $won->sum('seats'),
                'value' => (string) $won->sum('total'),
            ];
        });

        $top = $bookings->filter(fn (TravelBooking $booking): bool => in_array($booking->status, $confirmed, true))
            ->groupBy('travel_package_id')->map->count()->sortDesc()->take(5);

        return view('panels.travel.reports', [
            'months' => $months,
            'currency' => $countryContext->current()->currency,
            'conversion' => $bookings->isEmpty() ? null : round(100 * $bookings->filter(fn (TravelBooking $booking): bool => in_array($booking->status, $confirmed, true))->count() / $bookings->count(), 1),
            'top' => $top,
            'packages' => TravelPackage::query()->whereIn('id', $top->keys())->pluck('title_en', 'id'),
        ]);
    }
}
