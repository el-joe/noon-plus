<?php

namespace App\Domains\Vendors\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Panels\Support\AdminAlerts;
use App\Domains\Panels\Support\PanelNotifications;
use App\Domains\Vendors\Enums\ChangeRequestSection;
use App\Domains\Vendors\Enums\ChangeRequestStatus;
use App\Domains\Vendors\Models\ChangeRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Change requests for locked profile sections (lifecycle catalogue §7.3,
 * SRS §4.1.5, §12.2): store name, legal details, business address and bank
 * details of a store or travel agency are locked and change only through
 * Nawy. One open request per section; approval applies the change to the
 * vendor and keeps the before / after diff on the request (and in the
 * history meta); a bank change is only approved after the admin confirms
 * the bank account was verified. The requester may cancel while pending.
 */
class ChangeRequests
{
    public function __construct(private StateMachine $machine, private CountryContext $countryContext, private AdminAlerts $alerts) {}

    /**
     * The section's values as stored on the vendor now.
     *
     * @return array<string, mixed>
     */
    public function currentValues(Vendor $vendor, ChangeRequestSection $section): array
    {
        if ($section === ChangeRequestSection::StoreName) {
            return ['store_name' => $vendor->store_name];
        }

        $stored = $vendor->business_details[$section->storageKey()] ?? [];

        return collect(array_keys($section->fields()))->mapWithKeys(fn (string $field): array => [$field => $stored[$field] ?? null])->all();
    }

    /**
     * @param  array<string, mixed>  $values  validated against the section's fields
     */
    public function submit(Vendor $vendor, string $requesterType, int $userId, ChangeRequestSection $section, array $values, ?string $note): ChangeRequest
    {
        $values = collect($section->fields())->keys()->mapWithKeys(fn (string $field): array => [$field => isset($values[$field]) ? trim((string) $values[$field]) : null])->all();

        if ($values === $this->currentValues($vendor, $section)) {
            throw new TransitionRefused(__('Nothing changed in this section.'));
        }

        $request = DB::connection($vendor->getConnectionName())->transaction(function () use ($vendor, $requesterType, $userId, $section, $values, $note): ChangeRequest {
            Vendor::query()->lockForUpdate()->findOrFail($vendor->id);

            if (ChangeRequest::query()->where('vendor_id', $vendor->id)->where('section', $section)->where('status', ChangeRequestStatus::Pending)->exists()) {
                throw new TransitionRefused(__('A change to this section is already waiting for review.'));
            }

            $request = ChangeRequest::query()->create([
                'requester_type' => $requesterType,
                'vendor_id' => $vendor->id,
                'requested_by_user_id' => $userId,
                'section' => $section,
                'current_values' => $this->currentValues($vendor, $section),
                'requested_values' => $values,
                'note' => $note,
                'status' => ChangeRequestStatus::Pending,
            ]);
            $request->forceFill(['number' => sprintf('CR-%s-%06d', strtoupper($this->countryContext->current()->iso2), $request->id)])->save();
            $this->machine->created($request, $requesterType === 'travel_agency' ? ActorType::Agency : ActorType::Vendor, $userId);

            return $request;
        });

        $this->alerts->notify(AdminPermission::ChangeRequestsReview, 'change_request.submitted',
            __('Profile change request :number', ['number' => $request->number]), $vendor->store_name.' · '.$section->label(), '/change-requests/'.$request->id);

        return $request;
    }

    public function approve(ChangeRequest $request, int $adminUserId, bool $bankVerified): ChangeRequest
    {
        if ($request->section === ChangeRequestSection::BankDetails && ! $bankVerified) {
            throw new TransitionRefused(__('Confirm the new bank account was verified before approving a bank change.'));
        }

        $request = DB::connection($request->getConnectionName())->transaction(function () use ($request, $adminUserId, $bankVerified): ChangeRequest {
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($request->vendor_id);
            $before = $this->currentValues($vendor, $request->section);
            $after = $request->requested_values;

            if ($request->section === ChangeRequestSection::StoreName) {
                $vendor->forceFill(['store_name' => $after['store_name']])->save();
            } else {
                $details = $vendor->business_details ?? [];
                $key = $request->section->storageKey();
                $details[$key] = array_merge($details[$key] ?? [], $after);
                $vendor->forceFill(['business_details' => $details])->save();
            }

            $diff = collect($after)->filter(fn ($value, string $field): bool => ($before[$field] ?? null) !== $value)
                ->map(fn ($value, string $field): array => ['from' => $before[$field] ?? null, 'to' => $value])->all();

            activity('vendors')->performedOn($vendor)->causedBy(User::query()->find($adminUserId))->event('change_request_applied')
                ->withProperties([
                    'country' => $this->countryContext->current()->code,
                    'change_request' => $request->number,
                    'section' => $request->section->value,
                    'old' => $request->section === ChangeRequestSection::BankDetails ? array_map(fn (): string => '[hidden]', $before) : $before,
                    'attributes' => $request->section === ChangeRequestSection::BankDetails ? array_map(fn (): string => '[changed]', $after) : $after,
                ])
                ->log("Vendor {$vendor->store_name}: change request {$request->number} applied");

            return $this->machine->move($request, ChangeRequestStatus::Approved, ActorType::Admin, $adminUserId, null, [
                'applied_diff' => $diff,
                'bank_verified' => $bankVerified,
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
            ], ['diff_fields' => array_keys($diff)]);
        });

        $this->tellRequester($request, 'change_request.approved', __('Change request :number approved', ['number' => $request->number]),
            __('Your :section was updated.', ['section' => $request->section->label()]));

        return $request;
    }

    public function reject(ChangeRequest $request, int $adminUserId, string $reason): ChangeRequest
    {
        $request = $this->machine->move($request, ChangeRequestStatus::Rejected, ActorType::Admin, $adminUserId, $reason, [
            'rejection_reason' => mb_substr($reason, 0, 255), 'reviewed_by' => $adminUserId, 'reviewed_at' => now(),
        ]);
        $this->tellRequester($request, 'change_request.rejected', __('Change request :number rejected', ['number' => $request->number]), $reason);

        return $request;
    }

    public function cancel(ChangeRequest $request, int $userId): ChangeRequest
    {
        return $this->machine->move($request, ChangeRequestStatus::Cancelled, $request->requester_type === 'travel_agency' ? ActorType::Agency : ActorType::Vendor, $userId, 'Cancelled by the requester');
    }

    private function tellRequester(ChangeRequest $request, string $type, string $title, ?string $body): void
    {
        if ($request->requester_type === 'travel_agency') {
            $ownerId = Vendor::query()->whereKey($request->vendor_id)->value('owner_user_id');

            if ($ownerId !== null) {
                app(PanelNotifications::class)->notify((int) $ownerId, 'travel_agency', $type, $title, $body, '/change-requests');
            }

            return;
        }

        NotifySellerOfStateChange::notifyVendor($request->vendor_id, $type, $title, $body, '/change-requests');
    }
}
