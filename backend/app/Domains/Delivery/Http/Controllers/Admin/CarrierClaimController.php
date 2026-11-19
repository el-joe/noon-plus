<?php

namespace App\Domains\Delivery\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Delivery\Enums\ClaimStatus;
use App\Domains\Delivery\Enums\ClaimType;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Delivery\Support\CarrierClaims;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\SubOrder;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Delivery & logistics → Carrier claims (SRS §4.1.11): open a claim
 * for a lost or damaged shipment, review, approve (amount) or reject, then
 * mark it compensated (booked to the seller or the customer).
 */
class CarrierClaimController extends Controller
{
    public function __construct(private CountryContext $countryContext, private CarrierClaims $claims, private MoneyFactory $money) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ClaimStatus::class)]]);

        return view('panels.admin.delivery.claims', [
            'claims' => CarrierClaim::query()->with(['carrier', 'subOrder'])
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sub_order_number' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::enum(ClaimType::class)],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['required', $this->amountRule()],
            'compensate_to' => ['required', Rule::in([CarrierClaim::COMPENSATE_VENDOR, CarrierClaim::COMPENSATE_CUSTOMER])],
        ]);
        $subOrder = SubOrder::query()->where('number', trim($data['sub_order_number']))->first();

        if ($subOrder === null) {
            // A typo in the parcel number is a form error, not a 404 page (browser test BT-ADM-002).
            return back()->withInput()->withErrors(['sub_order_number' => __('No parcel with this number in :country.', ['country' => $this->countryContext->current()->name_en])]);
        }

        $claim = $this->claims->open($subOrder, ClaimType::from($data['type']), $data['description'], $this->money->of($data['amount'], $this->countryContext->current()->currency), $data['compensate_to'], ActorType::Admin, $request->user()->id);

        return back()->with('status', __('Claim :number submitted.', ['number' => $claim->number]));
    }

    public function act(Request $request, int $claim, string $action): RedirectResponse
    {
        $claim = CarrierClaim::query()->findOrFail($claim);
        $userId = $request->user()->id;

        match ($action) {
            'review' => $this->claims->review($claim, $userId),
            'approve' => $this->claims->approve($claim, $this->money->of($request->validate(['amount' => ['required', $this->amountRule()]])['amount'], $claim->currency), $userId, $request->input('note')),
            'reject' => $this->claims->reject($claim, $userId, $request->validate(['note' => ['required', 'string', 'max:255']])['note']),
            'compensate' => $this->claims->compensate($claim, ActorType::Admin, $userId),
        };

        return back()->with('status', __('Claim :number updated.', ['number' => $claim->number]));
    }

    private function amountRule(): string
    {
        $decimals = $this->money->zero($this->countryContext->current()->currency)->getCurrency()->getDefaultFractionDigits();

        return 'regex:/^\d{1,13}(\.\d{1,'.$decimals.'})?$/';
    }
}
