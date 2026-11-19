<?php

namespace App\Domains\Travel\Support;

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelDeparture;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Travel packages (SRS §12.2, lifecycle catalogue §5.2, Figma 105:15807):
 * the agency drafts a trip (itinerary, inclusions, cancellation tiers),
 * adds departures with seat capacity and room prices, uploads its trip
 * contract file and submits; an admin approves or rejects. Sold out and
 * expiry follow the departures.
 */
class TravelPackages
{
    public const DISK = 'local';

    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private VerticalSettings $settings,
        private ContractResolver $resolver,
        private ContractSigner $signer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Vendor $agency, array $data, int $userId): TravelPackage
    {
        $this->assertAgency($agency);
        $package = new TravelPackage($this->packageData($data) + ['vendor_id' => $agency->id, 'slug' => Str::slug($data['title_en']).'-'.Str::lower(Str::random(6))]);
        $package->forceFill(['status' => PackageStatus::Draft])->save();
        $this->machine->created($package, ActorType::Agency, $userId);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TravelPackage $package, array $data): TravelPackage
    {
        if (! in_array($package->status, [PackageStatus::Draft, PackageStatus::Rejected], true)) {
            throw new TransitionRefused(__('Only a draft package can be edited.'));
        }

        $package->fill($this->packageData($data))->save();

        return $package;
    }

    public function attachContract(TravelPackage $package, UploadedFile $file): TravelPackage
    {
        $package->forceFill(['contract_path' => $file->store('travel/contracts/'.$this->countryContext->current()->code, self::DISK)])->save();

        return $package;
    }

    /**
     * @param  array<string, string>  $roomPrices  room type => price per person
     */
    public function addDeparture(TravelPackage $package, string $departsOn, string $returnsOn, int $capacity, array $roomPrices): TravelDeparture
    {
        if (in_array($package->status, [PackageStatus::Expired], true)) {
            throw new TransitionRefused(__('This package has expired.'));
        }

        if ($capacity < 1 || $returnsOn < $departsOn || now()->toDateString() >= $departsOn) {
            throw new TransitionRefused(__('A departure needs a future date, a return after it and at least one seat.'));
        }

        $types = (array) $this->settings->get('travel.room_types');
        $prices = array_filter($roomPrices, fn ($price, $type): bool => in_array($type, $types, true) && is_numeric($price) && bccomp((string) $price, '0', 4) > 0, ARRAY_FILTER_USE_BOTH);

        if ($prices === []) {
            throw new TransitionRefused(__('Give at least one room price.'));
        }

        return DB::connection($package->getConnectionName())->transaction(function () use ($package, $departsOn, $returnsOn, $capacity, $prices): TravelDeparture {
            $departure = $package->departures()->create(['departs_on' => $departsOn, 'returns_on' => $returnsOn, 'seat_capacity' => $capacity, 'status' => 'open']);

            foreach ($prices as $type => $price) {
                $departure->rooms()->create(['room_type' => $type, 'price_per_person' => $this->money->toStorage($this->money->of((string) $price, $this->countryContext->current()->currency))]);
            }

            if ($package->status === PackageStatus::SoldOut) {
                $this->machine->move($package, PackageStatus::Active, ActorType::System, null, 'New departure added');
            }

            return $departure;
        });
    }

    /**
     * Capacity can shrink only down to the seats already held or booked.
     */
    public function resizeDeparture(TravelDeparture $departure, int $capacity): TravelDeparture
    {
        return DB::connection($departure->getConnectionName())->transaction(function () use ($departure, $capacity): TravelDeparture {
            $locked = TravelDeparture::query()->lockForUpdate()->findOrFail($departure->id);

            if ($capacity < $locked->seats_reserved) {
                throw new TransitionRefused(__(':count seats are already held or booked.', ['count' => $locked->seats_reserved]));
            }

            $locked->forceFill(['seat_capacity' => $capacity])->save();
            $this->syncSoldOut($locked->package);

            return $locked;
        });
    }

    public function submit(TravelPackage $package, int $userId): TravelPackage
    {
        $this->assertAgency($package->vendor);
        $agreement = $this->resolver->current(ContractKind::TravelAgency, $this->countryContext->current());

        if ($agreement !== null && ! $this->signer->hasCurrent($agreement, SignerType::Agency, $package->vendor_id)) {
            throw new TransitionRefused(__('Sign the travel agency agreement first.'));
        }

        $future = $package->departures()->where('departs_on', '>', now()->toDateString())->whereHas('rooms')->count();

        if ($future === 0) {
            throw new TransitionRefused(__('Add at least one future departure with room prices.'));
        }

        if (empty($package->inclusions) || empty($package->itinerary)) {
            throw new TransitionRefused(__('Describe the itinerary and what is included.'));
        }

        if ($package->contract_path === null) {
            throw new TransitionRefused(__('Upload the trip contract file.'));
        }

        return $this->machine->move($package, PackageStatus::PendingReview, ActorType::Agency, $userId, null, ['submitted_at' => now()]);
    }

    public function approve(TravelPackage $package, int $adminUserId): TravelPackage
    {
        $this->machine->move($package, PackageStatus::Active, ActorType::Admin, $adminUserId, 'Approved', ['approved_at' => now(), 'rejection_reason' => null]);

        return $this->syncSoldOut($package);
    }

    public function reject(TravelPackage $package, int $adminUserId, string $reason): TravelPackage
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new TransitionRefused(__('A rejection needs a reason for the agency.'));
        }

        return $this->machine->move($package, PackageStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * Active ⇄ sold out from the seats left on future open departures.
     */
    public function syncSoldOut(TravelPackage $package): TravelPackage
    {
        $package->refresh();

        if (! in_array($package->status, [PackageStatus::Active, PackageStatus::SoldOut], true)) {
            return $package;
        }

        $seatsLeft = (int) $package->departures()->where('status', 'open')->where('departs_on', '>', now()->toDateString())
            ->selectRaw('COALESCE(SUM(seat_capacity - seats_reserved), 0) as seats_left')->value('seats_left');

        if ($seatsLeft === 0 && $package->status === PackageStatus::Active) {
            return $this->machine->move($package, PackageStatus::SoldOut, ActorType::System, null, 'No seats left');
        }

        if ($seatsLeft > 0 && $package->status === PackageStatus::SoldOut) {
            return $this->machine->move($package, PackageStatus::Active, ActorType::System, null, 'Seats freed');
        }

        return $package;
    }

    /**
     * Active / sold-out packages whose last departure has left expire (daily job).
     */
    public function expireDue(): int
    {
        $count = 0;

        TravelPackage::query()->whereIn('status', [PackageStatus::Active, PackageStatus::SoldOut])
            ->whereDoesntHave('departures', fn ($query) => $query->where('departs_on', '>', now()->toDateString()))
            ->each(function (TravelPackage $package) use (&$count): void {
                $this->machine->move($package, PackageStatus::Expired, ActorType::System, null, 'Last departure passed');
                $count++;
            });

        return $count;
    }

    public function assertAgency(?Vendor $agency): void
    {
        if ($agency === null || $agency->type !== 'travel' || ! $agency->isActive()) {
            throw new TransitionRefused(__('The travel agency account is not active.'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function packageData(array $data): array
    {
        $policy = collect($data['cancellation_policy'] ?? [])
            ->map(fn ($tier): array => ['days_before' => (int) ($tier['days_before'] ?? 0), 'refund_percent' => min(100, max(0, (int) ($tier['refund_percent'] ?? 0)))])
            ->sortByDesc('days_before')->values()->all();

        return array_intersect_key($data, array_flip(['title_en', 'title_ar', 'description_en', 'description_ar', 'trip_type', 'destination_city', 'destination_country', 'nights', 'itinerary', 'inclusions', 'exclusions']))
            + (array_key_exists('cancellation_policy', $data) ? ['cancellation_policy' => $policy] : []);
    }
}
