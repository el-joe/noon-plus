<?php

namespace App\Domains\Customers\Http\Controllers\Admin;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Enums\CustomerAccountStatus;
use App\Domains\Customers\Support\CustomerAccountStateMachine;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\LikeSearch;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Customers (QC-016, matrix "customers"): the selected country's
 * customers, searchable by name or email, with suspend / reactivate / ban
 * through the customer account machine.
 */
class CustomerAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(CustomerAccountStatus::class)],
        ]);

        $customers = $this->customers()
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(fn ($match) => $match->where('email', 'like', LikeSearch::contains($q))->orWhere('name', 'like', LikeSearch::contains($q))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('panels.admin.customers.index', [
            'customers' => $customers,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function status(Request $request, int $user, CustomerAccountStateMachine $machine): RedirectResponse
    {
        $customer = $this->customers()->findOrFail($user);
        $data = $request->validate([
            'status' => ['required', Rule::enum(CustomerAccountStatus::class)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $machine->move($customer, CustomerAccountStatus::from($data['status']), ActorType::Admin, $request->user()->id, $data['reason']);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Customer account updated.'));
    }

    /**
     * @return Builder<User>
     */
    private function customers()
    {
        $countryId = $this->countryContext->current()->id;

        return User::query()->whereHas('memberships', fn ($membership) => $membership->where('role', MembershipRole::Customer)->where('country_id', $countryId));
    }
}
