<?php

namespace App\Domains\Travel\Http\Controllers\Api;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelBooking;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Models\TravelDepartureRoom;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Travel\Support\TravelBookings;
use App\Domains\Travel\Support\TravelInquiries;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicUrl;
use App\Shared\Validation\ContactDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Travel API (storefront + app, SRS §12.2, Figma travel pages): packages
 * on sale with departures, room prices and seats left; inquiries; and
 * booking requests without payment (owner decision WP6) for guests and
 * customers — request (or hold then submit), view, cancel, change
 * requests. The agency settles payment with the customer.
 */
class TravelController extends Controller
{
    public const TOKEN_HEADER = 'X-Booking-Token';

    public function __construct(private TravelBookings $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['destination' => ['nullable', 'string', 'max:80'], 'trip_type' => ['nullable', 'string', 'max:24'], 'month' => ['nullable', 'date_format:Y-m']]);
        $packages = TravelPackage::query()->whereIn('status', [PackageStatus::Active, PackageStatus::SoldOut])
            ->whereHas('vendor', fn ($vendor) => $vendor->where('status', 'active'))
            ->when($filters['destination'] ?? null, fn ($query, $destination) => $query->where('destination_city', 'like', '%'.$destination.'%'))
            ->when($filters['trip_type'] ?? null, fn ($query, $type) => $query->where('trip_type', $type))
            ->when($filters['month'] ?? null, fn ($query, $month) => $query->whereHas('departures', fn ($departure) => $departure->where('departs_on', 'like', $month.'%')))
            ->with(['vendor', 'departures.rooms'])->latest('approved_at')->paginate(24);

        return ApiResponse::success(collect($packages->items())->map(fn (TravelPackage $package): array => $this->card($package))->all(), ['total' => $packages->total(), 'page' => $packages->currentPage()]);
    }

    public function show(string $slug): JsonResponse
    {
        $package = TravelPackage::query()->where('slug', $slug)->whereIn('status', [PackageStatus::Active, PackageStatus::SoldOut])->with(['vendor', 'departures.rooms'])->firstOrFail();

        return ApiResponse::success($this->card($package) + [
            'description' => app()->getLocale() === 'ar' ? $package->description_ar : $package->description_en,
            'itinerary' => $package->itinerary ?? [],
            'inclusions' => $package->inclusions ?? [],
            'exclusions' => $package->exclusions ?? [],
            'cancellation_policy' => $package->cancellation_policy ?? [],
            'departures' => $package->departures->where('departs_on', '>', now())->sortBy('departs_on')->values()->map(fn (TravelDeparture $departure): array => [
                'id' => $departure->id,
                'departs_on' => $departure->departs_on->toDateString(),
                'returns_on' => $departure->returns_on->toDateString(),
                'seats_left' => max(0, $departure->seat_capacity - $departure->seats_reserved),
                'status' => $departure->seat_capacity - $departure->seats_reserved <= 0 ? 'sold_out' : $departure->status,
                'rooms' => $departure->rooms->map(fn (TravelDepartureRoom $room): array => ['type' => $room->room_type, 'price_per_person' => MoneyResource::make((string) $room->price_per_person)])->values()->all(),
            ])->all(),
        ]);
    }

    public function inquire(Request $request, string $slug, TravelInquiries $inquiries): JsonResponse
    {
        $data = $request->validate(ContactDetails::rules($request) + ['message' => ['required', 'string', 'max:2000']]);
        $package = TravelPackage::query()->where('slug', $slug)->firstOrFail();
        $inquiry = $inquiries->create($package, $request->user('sanctum')?->id, ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'message' => $data['message']]);

        return ApiResponse::success(['id' => $inquiry->id, 'status' => $inquiry->status->value], status: 201);
    }

    /**
     * Booking request (owner decision WP6: no payment). Guests and
     * signed-in customers alike; `submit=false` only holds the seats for
     * `travel.seat_hold_minutes` until `POST bookings/{number}/submit`.
     * The `X-Booking-Token` response header (and, for older clients, the
     * body's `access_token`) is what a guest sends to see or cancel the
     * request; the confirmation email links to the status page with it.
     */
    public function store(Request $request, int $departure): JsonResponse
    {
        $data = $request->validate(ContactDetails::rules($request) + [
            'rooms' => ['required', 'array', 'min:1', 'max:10'],
            'rooms.*.room_type' => ['required', 'string', 'max:12'],
            'rooms.*.travellers' => ['required', 'integer', 'min:1', 'max:20'],
            'traveller_names' => ['nullable', 'array', 'max:20'],
            'traveller_names.*' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:1000'],
            'submit' => ['sometimes', 'boolean'],
            'ref' => ['nullable', 'string', 'max:120'],
        ]);
        $departure = TravelDeparture::query()->with('package.vendor')->findOrFail($departure);
        $booking = $this->bookings->request($departure, $request->user('sanctum'), $data['rooms'], [
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'message' => $data['message'] ?? null,
        ], $data['traveller_names'] ?? [], (bool) ($data['submit'] ?? true), $data['ref'] ?? null);

        return ApiResponse::success($this->present($booking) + ['access_token' => $booking->access_token], status: 201)
            ->header(self::TOKEN_HEADER, $booking->access_token);
    }

    /**
     * Mail the guest's booking status links again (G-W20). Always 202: the
     * answer never says whether a booking exists for the email.
     */
    public function resendLink(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'email', 'max:255']])['email'];
        $this->bookings->resendLinks($email);

        return ApiResponse::success(['sent' => true], status: 202);
    }

    public function bookings(Request $request): JsonResponse
    {
        return ApiResponse::success(TravelBooking::query()->where('customer_user_id', $request->user()->id)->with(['package', 'departure'])->latest('id')->get()->map(fn (TravelBooking $booking): array => $this->present($booking))->all());
    }

    public function showBooking(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->present($this->own($request, $number)->load('travellers')));
    }

    public function submit(Request $request, string $number): JsonResponse
    {
        $booking = $this->own($request, $number);

        return ApiResponse::success($this->present($this->bookings->submit($booking, $request->user('sanctum')?->id)->refresh()));
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        $booking = $this->bookings->cancel($this->own($request, $number), ActorType::Customer, $request->user('sanctum')?->id, $reason);

        return ApiResponse::success($this->present($booking));
    }

    public function modify(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:20'], 'details' => ['nullable', 'array']]);
        $modification = $this->bookings->requestModification($this->own($request, $number), $data['type'], $data['details'] ?? [], $request->user('sanctum')?->id);

        return ApiResponse::success(['id' => $modification->id, 'status' => $modification->status], status: 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TravelBooking $booking): array
    {
        $booking->loadMissing(['package', 'departure', 'vendor']);

        return [
            'number' => $booking->number,
            'status' => $booking->status->value,
            'agency' => $booking->vendor->store_name,
            'package' => ['slug' => $booking->package->slug, 'title' => app()->getLocale() === 'ar' ? $booking->package->title_ar : $booking->package->title_en],
            'departs_on' => $booking->departure->departs_on->toDateString(),
            'returns_on' => $booking->departure->returns_on->toDateString(),
            'rooms' => $booking->rooms,
            'seats' => $booking->seats,
            'quoted_total' => MoneyResource::of((string) $booking->total, $booking->currency),
            'payment' => 'with_agency',
            'contact' => ['name' => $booking->contact_name, 'email' => $booking->contact_email, 'phone' => $booking->contact_phone],
            'travellers' => $booking->relationLoaded('travellers') ? $booking->travellers->pluck('full_name')->all() : null,
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'confirmation_number' => $booking->confirmation_number,
            'decision_reason' => $booking->decision_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(TravelPackage $package): array
    {
        $from = $package->departures->where('departs_on', '>', now())->flatMap->rooms->pluck('price_per_person')
            ->reduce(fn (?string $lowest, $price): string => $lowest === null || bccomp((string) $price, $lowest, 4) < 0 ? (string) $price : $lowest);

        return [
            'slug' => $package->slug,
            'title' => app()->getLocale() === 'ar' ? $package->title_ar : $package->title_en,
            'agency' => $package->vendor->store_name,
            'trip_type' => $package->trip_type,
            'destination' => $package->destination_city,
            'nights' => $package->nights,
            'from_price' => MoneyResource::make($from),
            'image' => PublicUrl::of($package->cover_path) === null ? null : ['url' => PublicUrl::of($package->cover_path), 'alt' => app()->getLocale() === 'ar' ? $package->title_ar : $package->title_en],
            'cover_url' => PublicUrl::of($package->cover_path),
            'status' => $package->status->value,
        ];
    }

    /**
     * The caller's booking: the signed-in owner's, or a guest's with the
     * `X-Booking-Token` header (the access token given at creation).
     */
    private function own(Request $request, string $number): TravelBooking
    {
        $booking = TravelBooking::query()->where('number', $number)->with(['package', 'departure', 'vendor'])->firstOrFail();
        $userId = $request->user('sanctum')?->id;
        $token = (string) $request->header(self::TOKEN_HEADER, '');
        $allowed = ($userId !== null && $booking->customer_user_id === $userId) || ($token !== '' && hash_equals($booking->access_token, $token));
        abort_unless($allowed, 404);

        return $booking;
    }
}
