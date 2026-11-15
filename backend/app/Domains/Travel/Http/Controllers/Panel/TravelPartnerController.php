<?php

namespace App\Domains\Travel\Http\Controllers\Panel;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Http\Controllers\PanelDashboardController;
use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Travel\Enums\InquiryStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelBookingModification;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Models\TravelInquiry;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\SeatAllocator;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelInquiries;
use App\Domains\Travel\Support\TravelPackages;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Travel Partner panel (travel-agency., SRS §4.5, Figma 90:3098 dashboard,
 * 90:3414 departures & capacity, 105:15807 package editor, 105:16303
 * bookings & documents). Every query is scoped to the signed-in agency.
 */
class TravelPartnerController extends Controller
{
    public function __construct(private TravelPackages $packages, private TravelBookings $bookings, private CountryContext $countryContext) {}

    public function dashboard(Request $request): View
    {
        $agency = $request->attributes->get('agency');

        if ($agency === null) {
            return app(PanelDashboardController::class)($request);
        }

        $bookings = TravelBooking::query()->where('vendor_id', $agency->id);

        return view('panels.travel.dashboard', [
            'agency' => $agency,
            'currency' => $this->countryContext->current()->currency,
            'stats' => [
                'live_packages' => TravelPackage::query()->where('vendor_id', $agency->id)->where('status', 'active')->count(),
                'awaiting_decision' => (clone $bookings)->where('status', BookingStatus::Requested)->count(),
                'confirmed' => (clone $bookings)->where('status', BookingStatus::Confirmed)->count(),
                'revenue' => (string) (clone $bookings)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed])->sum('total'),
                'new_inquiries' => TravelInquiry::query()->where('vendor_id', $agency->id)->where('status', InquiryStatus::New)->count(),
            ],
            'upcoming' => TravelDeparture::query()->whereHas('package', fn ($query) => $query->where('vendor_id', $agency->id))
                ->where('departs_on', '>=', today())->with('package')->orderBy('departs_on')->limit(8)->get(),
        ]);
    }

    public function packages(Request $request): View
    {
        return view('panels.travel.packages', [
            'packages' => TravelPackage::query()->where('vendor_id', $this->agency($request)->id)->withCount('departures')->latest('id')->paginate(20),
        ]);
    }

    public function createPackage(): View
    {
        return view('panels.travel.package-editor', ['package' => null, 'roomTypes' => (array) config('nawy.travel.room_types')]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $package = $this->packages->create($this->agency($request), $this->packageData($request), $request->user()->id);

        return redirect()->route('travel_agency.packages.edit', $package->id)->with('status', __('Draft saved. Add departures, then submit for review.'));
    }

    public function editPackage(Request $request, int $package): View
    {
        return view('panels.travel.package-editor', [
            'package' => $this->package($request, $package)->load('departures.rooms'),
            'roomTypes' => (array) config('nawy.travel.room_types'),
        ]);
    }

    public function updatePackage(Request $request, int $package): RedirectResponse
    {
        $package = $this->package($request, $package);
        $this->packages->update($package, $this->packageData($request));

        if ($request->hasFile('contract')) {
            $request->validate(['contract' => ['file', 'mimes:pdf,doc,docx', 'max:20480']]);
            $this->packages->attachContract($package, $request->file('contract'));
        }

        return back()->with('status', __('Package saved.'));
    }

    public function addDeparture(Request $request, int $package): RedirectResponse
    {
        $data = $request->validate([
            'departs_on' => ['required', 'date'],
            'returns_on' => ['required', 'date', 'after_or_equal:departs_on'],
            'seat_capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'rooms' => ['required', 'array'],
            'rooms.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->packages->addDeparture($this->package($request, $package), $data['departs_on'], $data['returns_on'], (int) $data['seat_capacity'], array_filter($data['rooms'], fn ($price) => $price !== null && $price !== ''));

        return back()->with('status', __('Departure added.'));
    }

    public function submitPackage(Request $request, int $package): RedirectResponse
    {
        $this->packages->submit($this->package($request, $package), $request->user()->id);

        return back()->with('status', __('Submitted for review.'));
    }

    public function departures(Request $request, SeatAllocator $seats): View
    {
        $agency = $this->agency($request);
        $departures = TravelDeparture::query()->whereHas('package', fn ($query) => $query->where('vendor_id', $agency->id))
            ->with(['package', 'rooms'])
            ->withSum(['bookings as held_seats' => fn ($query) => $query->where('status', BookingStatus::Held)], 'seats')
            ->orderBy('departs_on')->paginate(30);

        return view('panels.travel.departures', ['departures' => $departures, 'holdMinutes' => (int) config('nawy.travel.seat_hold_minutes')]);
    }

    public function resizeDeparture(Request $request, int $departure): RedirectResponse
    {
        $capacity = (int) $request->validate(['seat_capacity' => ['required', 'integer', 'min:0', 'max:1000']])['seat_capacity'];
        $departure = TravelDeparture::query()->whereHas('package', fn ($query) => $query->where('vendor_id', $this->agency($request)->id))->findOrFail($departure);
        $this->packages->resizeDeparture($departure, $capacity);

        return back()->with('status', __('Capacity updated.'));
    }

    public function bookings(Request $request): View
    {
        $status = $request->query('status');

        return view('panels.travel.bookings', [
            'bookings' => TravelBooking::query()->where('vendor_id', $this->agency($request)->id)
                ->when(is_string($status) && BookingStatus::tryFrom($status) !== null, fn ($query) => $query->where('status', $status))
                ->with(['package', 'departure', 'travellers'])->latest('id')->paginate(30),
            'modifications' => TravelBookingModification::query()->where('status', 'requested')->whereHas('booking', fn ($query) => $query->where('vendor_id', $this->agency($request)->id))->with('booking')->get(),
            'status' => $status,
        ]);
    }

    public function confirmBooking(Request $request, int $booking): RedirectResponse
    {
        $this->bookings->confirm($this->booking($request, $booking), $request->user()->id);

        return back()->with('status', __('Booking confirmed; the customer was emailed.'));
    }

    public function rejectBooking(Request $request, int $booking): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $this->bookings->reject($this->booking($request, $booking), $request->user()->id, $reason);

        return back()->with('status', __('Booking request rejected; the seats are free again.'));
    }

    public function cancelBooking(Request $request, int $booking): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $this->bookings->cancel($this->booking($request, $booking), ActorType::Vendor, $request->user()->id, $reason);

        return back()->with('status', __('Booking cancelled; the customer was emailed.'));
    }

    public function decideModification(Request $request, int $modification): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        $modification = TravelBookingModification::query()->whereHas('booking', fn ($query) => $query->where('vendor_id', $this->agency($request)->id))->findOrFail($modification);
        $this->bookings->decideModification($modification, $data['decision'] === 'approve', $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('Change request answered.'));
    }

    public function inquiries(Request $request): View
    {
        return view('panels.travel.inquiries', ['inquiries' => TravelInquiry::query()->where('vendor_id', $this->agency($request)->id)->with('package')->latest('id')->paginate(30)]);
    }

    public function moveInquiry(Request $request, int $inquiry, TravelInquiries $inquiries): RedirectResponse
    {
        $to = InquiryStatus::from($request->validate(['status' => ['required', 'in:contacted,converted,closed']])['status']);
        $inquiries->move(TravelInquiry::query()->where('vendor_id', $this->agency($request)->id)->findOrFail($inquiry), $to, $request->user()->id);

        return back()->with('status', __('Inquiry updated.'));
    }

    private function agency(Request $request): Vendor
    {
        return $request->attributes->get('agency');
    }

    private function package(Request $request, int $id): TravelPackage
    {
        return TravelPackage::query()->where('vendor_id', $this->agency($request)->id)->findOrFail($id);
    }

    private function booking(Request $request, int $id): TravelBooking
    {
        return TravelBooking::query()->where('vendor_id', $this->agency($request)->id)->with(['package', 'departure', 'vendor'])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function packageData(Request $request): array
    {
        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:160'],
            'title_ar' => ['required', 'string', 'max:160'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'trip_type' => ['required', 'in:umrah,hajj,holiday,honeymoon,adventure,business'],
            'destination_city' => ['required', 'string', 'max:80'],
            'destination_country' => ['nullable', 'string', 'max:80'],
            'nights' => ['required', 'integer', 'min:0', 'max:90'],
            'itinerary_text' => ['nullable', 'string', 'max:5000'],
            'inclusions_text' => ['nullable', 'string', 'max:2000'],
            'exclusions_text' => ['nullable', 'string', 'max:2000'],
            'policy' => ['nullable', 'array'],
            'policy.*.days_before' => ['nullable', 'integer', 'min:0'],
            'policy.*.refund_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $lines = fn (?string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: [])));

        return $data + [
            'itinerary' => array_map(fn (string $line, int $index): array => ['day' => $index + 1, 'title' => $line], $lines($data['itinerary_text'] ?? null), array_keys($lines($data['itinerary_text'] ?? null))),
            'inclusions' => $lines($data['inclusions_text'] ?? null),
            'exclusions' => $lines($data['exclusions_text'] ?? null),
            'cancellation_policy' => array_values(array_filter($data['policy'] ?? [], fn ($tier): bool => isset($tier['days_before'], $tier['refund_percent']) && $tier['days_before'] !== null)),
        ];
    }
}
