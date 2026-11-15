<?php

namespace App\Domains\Travel\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Travel agency ops app (Sanctum `ops:travel_agency`): the Travel Partner
 * agency's dashboard, booking requests (confirm / reject through
 * {@see TravelBookings}) and departures with capacity changes through
 * {@see TravelPackages::resizeDeparture()}, like TravelPartnerController.
 * Another agency's booking or departure is a 404.
 */
class TravelOpsController extends Controller
{
    use RefusesTransitions;

    public function __construct(private TravelBookings $bookings, private CountryContext $countryContext) {}

    /**
     * `confirmed_this_month` = bookings confirmed since the 1st (country
     * time); `upcoming_departures` / `seats_left` = departures from today on.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $now = CarbonImmutable::now($this->countryContext->current()->timezone);
        $upcoming = $this->departures($agency)->where('departs_on', '>=', $now->toDateString())->get(['id', 'seat_capacity', 'seats_reserved']);

        return ApiResponse::success([
            'agency' => ['id' => $agency->id, 'name' => $agency->store_name],
            'kpis' => [
                'pending_requests' => TravelBooking::query()->where('vendor_id', $agency->id)->where('status', BookingStatus::Requested)->count(),
                'confirmed_this_month' => TravelBooking::query()->where('vendor_id', $agency->id)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed])
                    ->where('confirmed_at', '>=', $now->startOfMonth()->utc())->count(),
                'upcoming_departures' => $upcoming->count(),
                'seats_left' => (int) $upcoming->sum(fn (TravelDeparture $departure): int => max(0, (int) $departure->seat_capacity - (int) $departure->seats_reserved)),
            ],
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(BookingStatus::class)]]);
        $page = TravelBooking::query()->where('vendor_id', $this->agency($request)->id)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with(['package', 'departure'])->latest('id')->paginate(30);

        return ApiResponse::success($page->getCollection()->map(fn (TravelBooking $booking): array => $this->bookingRow($booking))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function confirm(Request $request, int $booking): JsonResponse
    {
        $booking = $this->booking($request, $booking);

        return $this->refusable(fn (): JsonResponse => $this->presentBooking($this->bookings->confirm($booking, $request->user()->id)));
    }

    /**
     * Same input as the panel: `reason` (5 to 255 characters).
     */
    public function reject(Request $request, int $booking): JsonResponse
    {
        $booking = $this->booking($request, $booking);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];

        return $this->refusable(fn (): JsonResponse => $this->presentBooking($this->bookings->reject($booking, $request->user()->id, $reason)));
    }

    public function departureList(Request $request): JsonResponse
    {
        $page = $this->departures($this->agency($request))->with('package')->orderBy('departs_on')->paginate(30);

        return ApiResponse::success($page->getCollection()->map(fn (TravelDeparture $departure): array => $this->departureRow($departure))->all(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    /**
     * `capacity` (0 to 1000 seats, not below the seats already held or booked).
     */
    public function capacity(Request $request, int $departure, TravelPackages $packages): JsonResponse
    {
        $departure = $this->departures($this->agency($request))->findOrFail($departure);
        $capacity = (int) $request->validate(['capacity' => ['required', 'integer', 'min:0', 'max:1000']])['capacity'];

        return $this->refusable(function () use ($packages, $departure, $capacity): JsonResponse {
            $packages->resizeDeparture($departure, $capacity);

            return ApiResponse::success($this->departureRow(TravelDeparture::query()->with('package')->findOrFail($departure->id)));
        });
    }

    private function agency(Request $request): Vendor
    {
        return $request->attributes->get('ops_workspace');
    }

    /**
     * @return Builder<TravelDeparture>
     */
    private function departures(Vendor $agency): Builder
    {
        return TravelDeparture::query()->whereHas('package', fn ($query) => $query->where('vendor_id', $agency->id));
    }

    private function booking(Request $request, int $id): TravelBooking
    {
        return TravelBooking::query()->where('vendor_id', $this->agency($request)->id)->with(['package', 'departure', 'vendor'])->findOrFail($id);
    }

    private function presentBooking(TravelBooking $booking): JsonResponse
    {
        return ApiResponse::success($this->bookingRow($booking->refresh()->load(['package', 'departure'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRow(TravelBooking $booking): array
    {
        $next = $booking->status->allowedNext();

        return [
            'id' => $booking->id,
            'number' => $booking->number,
            'status' => $booking->status->value,
            'package' => (string) $booking->package?->title_en,
            'departure_date' => $booking->departure?->departs_on?->toDateString(),
            'travellers' => (int) $booking->seats,
            'contact_name' => $booking->contact_name,
            'contact_phone' => $booking->contact_phone,
            'contact_email' => $booking->contact_email,
            'total' => MoneyResource::of($booking->total === null ? null : (string) $booking->total, $booking->currency),
            'submitted_at' => ($booking->requested_at ?? $booking->created_at)?->toIso8601String(),
            'can_confirm' => in_array(BookingStatus::Confirmed, $next, true),
            'can_reject' => in_array(BookingStatus::Rejected, $next, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departureRow(TravelDeparture $departure): array
    {
        $capacity = (int) $departure->seat_capacity;
        $booked = (int) $departure->seats_reserved;

        return [
            'id' => $departure->id,
            'package' => (string) $departure->package?->title_en,
            'starts_on' => $departure->departs_on?->toDateString(),
            'ends_on' => $departure->returns_on?->toDateString(),
            'capacity' => $capacity,
            'booked' => $booked,
            'seats_left' => max(0, $capacity - $booked),
            'status' => $departure->status,
        ];
    }
}
