<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Advertising\Enums\ParticipationRequestStatus;
use App\Domains\Advertising\Models\CouponParticipationInvitation;
use App\Domains\Advertising\Models\CouponParticipationRequest;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Coupon participation (SRS §10, lifecycle catalogue §4.5–4.6).
 *
 * The admin opens an invitation on a platform coupon (minimum fee,
 * capacity, closing time). Vendors and marketers request to join with an
 * offered fee ≥ the minimum while seats remain. Approving charges the fee
 * (store statement line / marketer wallet → `paid`) or, for a bank
 * transfer, waits for the proof to be confirmed (`approved_pending_payment`
 * → `paid`, Dr bank clearing / Cr ad revenue). Rejecting a paid request
 * refunds it (`refunded`). Open invitations close when their time passes
 * (every 15 min) or capacity is reached; closed invitations with paid
 * participants are activated daily (00:30): the coupon goes live and then
 * applies only to the participants' items.
 */
class CouponParticipations
{
    public const PROOF_DISK = 'local';

    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private AdvertiserBilling $billing,
        private Ledger $ledger,
    ) {}

    /**
     * @param  array{title: string, min_fee: string, capacity: int, closes_at: string, audience?: array<int, string>}  $data
     */
    public function create(Coupon $coupon, array $data, int $adminUserId): CouponParticipationInvitation
    {
        if ($coupon->scope !== CouponScope::Platform) {
            throw new TransitionRefused(__('Participation is for platform coupons.'));
        }

        $invitation = new CouponParticipationInvitation([
            'coupon_id' => $coupon->id,
            'title' => $data['title'],
            'currency' => $this->countryContext->current()->currency,
            'min_fee' => $this->money->toStorage($this->money->of($data['min_fee'], $this->countryContext->current()->currency)),
            'capacity' => (int) $data['capacity'],
            'audience' => $data['audience'] ?? ['vendor', 'marketer'],
            'closes_at' => $data['closes_at'],
            'created_by_user_id' => $adminUserId,
        ]);
        $invitation->forceFill(['status' => ParticipationInvitationStatus::Draft])->save();
        $this->machine->created($invitation, ActorType::Admin, $adminUserId);

        return $invitation;
    }

    public function open(CouponParticipationInvitation $invitation, int $adminUserId): CouponParticipationInvitation
    {
        if ($invitation->capacity < 1 || $invitation->closes_at->isPast() || bccomp((string) $invitation->min_fee, '0', 4) < 0) {
            throw new TransitionRefused(__('Set the minimum fee, capacity and a future closing time.'));
        }

        return $this->machine->move($invitation, ParticipationInvitationStatus::Open, ActorType::Admin, $adminUserId);
    }

    public function request(CouponParticipationInvitation $invitation, string $participantType, int $participantId, string $offeredFee, string $method, ?UploadedFile $proof, int $userId): CouponParticipationRequest
    {
        if ($invitation->status !== ParticipationInvitationStatus::Open || $invitation->closes_at->isPast()) {
            throw new TransitionRefused(__('This invitation is closed.'));
        }

        if (! in_array($participantType, (array) ($invitation->audience ?? []), true)) {
            throw new TransitionRefused(__('This invitation is not open to you.'));
        }

        if (! is_numeric($offeredFee) || bccomp($offeredFee, (string) $invitation->min_fee, 4) < 0) {
            throw new TransitionRefused(__('Offer at least :min.', ['min' => (string) $this->money->of((string) $invitation->min_fee, $invitation->currency)->getAmount()]));
        }

        if (! in_array($method, ['wallet', 'bank_transfer'], true) || ($method === 'bank_transfer' && $proof === null)) {
            throw new TransitionRefused(__('Pay from your balance or upload the bank transfer proof.'));
        }

        if ($this->seatsTaken($invitation) >= $invitation->capacity) {
            throw new TransitionRefused(__('All places are taken.'));
        }

        if ($invitation->requests()->where(['participant_type' => $participantType, 'participant_id' => $participantId])->exists()) {
            throw new TransitionRefused(__('You already asked to join.'));
        }

        $request = new CouponParticipationRequest([
            'coupon_participation_invitation_id' => $invitation->id,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'offered_fee' => $this->money->toStorage($this->money->of($offeredFee, $invitation->currency)),
            'payment_method' => $method,
            'proof_path' => $proof?->store('coupon-participation/proofs', self::PROOF_DISK),
        ]);
        $request->forceFill(['status' => ParticipationRequestStatus::Requested])->save();
        $this->machine->created($request, $participantType === AdvertiserBilling::VENDOR ? ActorType::Vendor : ActorType::Marketer, $userId);

        return $request;
    }

    public function approve(CouponParticipationRequest $request, int $adminUserId): CouponParticipationRequest
    {
        return DB::connection($request->getConnectionName())->transaction(function () use ($request, $adminUserId): CouponParticipationRequest {
            $invitation = CouponParticipationInvitation::query()->lockForUpdate()->findOrFail($request->coupon_participation_invitation_id);

            if (! in_array($invitation->status, [ParticipationInvitationStatus::Open, ParticipationInvitationStatus::Closed], true)) {
                throw new TransitionRefused(__('This invitation is no longer taking participants.'));
            }

            if ($this->paidCount($invitation) >= $invitation->capacity) {
                throw new TransitionRefused(__('All places are taken.'));
            }

            if ($request->payment_method === 'bank_transfer') {
                return $this->machine->move($request, ParticipationRequestStatus::ApprovedPendingPayment, ActorType::Admin, $adminUserId);
            }

            $this->billing->charge($request->participant_type, (int) $request->participant_id, $this->money->of((string) $request->offered_fee, $invitation->currency),
                VendorBalanceItem::TYPE_COUPON_PARTICIPATION, $request, "coupon-participation:{$request->id}:charge", "Coupon participation: {$invitation->title}");
            $this->machine->move($request, ParticipationRequestStatus::Paid, ActorType::Admin, $adminUserId, 'Charged', ['paid_at' => now()]);
            $this->closeIfFull($invitation);

            return $request;
        });
    }

    /**
     * Bank transfer proof confirmed by the admin.
     */
    public function confirmPayment(CouponParticipationRequest $request, int $adminUserId): CouponParticipationRequest
    {
        if ($request->status !== ParticipationRequestStatus::ApprovedPendingPayment) {
            throw new TransitionRefused(__('This request is not waiting for a transfer.'));
        }

        return DB::connection($request->getConnectionName())->transaction(function () use ($request, $adminUserId): CouponParticipationRequest {
            $invitation = $request->invitation;
            $fee = $this->money->of((string) $request->offered_fee, $invitation->currency);
            $this->ledger->post("coupon-participation:{$request->id}:bank", 'coupon_participation', (new Posting)
                ->debit(AccountRef::platform(LedgerAccountCode::BankClearing), $fee)
                ->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $fee), ActorType::Admin, $adminUserId, $request, "Coupon participation: {$invitation->title}");
            $this->machine->move($request, ParticipationRequestStatus::Paid, ActorType::Admin, $adminUserId, 'Bank transfer confirmed', ['paid_at' => now()]);
            $this->closeIfFull($invitation);

            return $request;
        });
    }

    public function reject(CouponParticipationRequest $request, int $adminUserId, ?string $note = null): CouponParticipationRequest
    {
        return DB::connection($request->getConnectionName())->transaction(function () use ($request, $adminUserId, $note): CouponParticipationRequest {
            if ($request->status !== ParticipationRequestStatus::Paid) {
                return $this->machine->move($request, ParticipationRequestStatus::Rejected, ActorType::Admin, $adminUserId, $note, ['decision_note' => $note]);
            }

            $this->refundPaid($request, $adminUserId, $note);

            return $request;
        });
    }

    /**
     * Admin cancels an invitation that has not gone live: every paid fee is refunded.
     */
    public function cancel(CouponParticipationInvitation $invitation, int $adminUserId): CouponParticipationInvitation
    {
        return DB::connection($invitation->getConnectionName())->transaction(function () use ($invitation, $adminUserId): CouponParticipationInvitation {
            $this->machine->move($invitation, ParticipationInvitationStatus::Cancelled, ActorType::Admin, $adminUserId);

            foreach ($invitation->requests()->get() as $request) {
                match ($request->status) {
                    ParticipationRequestStatus::Paid => $this->refundPaid($request, $adminUserId, 'Invitation cancelled'),
                    ParticipationRequestStatus::Requested, ParticipationRequestStatus::ApprovedPendingPayment => $this->machine->move($request, ParticipationRequestStatus::Rejected, ActorType::Admin, $adminUserId, 'Invitation cancelled'),
                    default => null,
                };
            }

            return $invitation;
        });
    }

    /**
     * `coupons:close-expired-participation-invitations` (every 15 min).
     */
    public function closeExpired(): int
    {
        $count = 0;

        CouponParticipationInvitation::query()->where('status', ParticipationInvitationStatus::Open)->where('closes_at', '<=', now())->each(function (CouponParticipationInvitation $invitation) use (&$count): void {
            $this->machine->move($invitation, ParticipationInvitationStatus::Closed, ActorType::System, null, 'Closing time passed');
            $count++;
        });

        return $count;
    }

    /**
     * Activate fulfilled invitations (daily 00:30): a closed invitation with
     * paid participants puts its coupon live; one without ends.
     *
     * @return array{activated: int, ended: int}
     */
    public function activateFulfilled(): array
    {
        $result = ['activated' => 0, 'ended' => 0];

        CouponParticipationInvitation::query()->where('status', ParticipationInvitationStatus::Closed)->each(function (CouponParticipationInvitation $invitation) use (&$result): void {
            DB::connection($invitation->getConnectionName())->transaction(function () use ($invitation, &$result): void {
                if ($this->paidCount($invitation) === 0) {
                    $this->machine->move($invitation, ParticipationInvitationStatus::Ended, ActorType::System, null, 'No paid participants');
                    $result['ended']++;

                    return;
                }

                $invitation->coupon->forceFill(['is_active' => true])->save();
                $this->machine->move($invitation, ParticipationInvitationStatus::Active, ActorType::System, null, 'Coupon live for participants', ['activated_at' => now()]);
                $result['activated']++;
            });
        });

        return $result;
    }

    /**
     * Stores whose items a participation coupon applies to; null = the
     * coupon has no participation (applies to everything as before).
     *
     * @return array<int, int>|null
     */
    public function restrictedVendorIds(Coupon $coupon): ?array
    {
        $invitation = CouponParticipationInvitation::query()->where('coupon_id', $coupon->id)->whereIn('status', [ParticipationInvitationStatus::Active, ParticipationInvitationStatus::Closed, ParticipationInvitationStatus::Open])->latest('id')->first();

        if ($invitation === null) {
            return null;
        }

        $paid = $invitation->requests()->where('status', ParticipationRequestStatus::Paid)->get();
        $vendorIds = $paid->where('participant_type', AdvertiserBilling::VENDOR)->pluck('participant_id')->map(fn ($id): int => (int) $id);
        $marketerStores = MarketerProfile::query()->whereKey($paid->where('participant_type', AdvertiserBilling::MARKETER)->pluck('participant_id'))->whereNotNull('vendor_id')->pluck('vendor_id')->map(fn ($id): int => (int) $id);

        return $vendorIds->merge($marketerStores)->unique()->values()->all();
    }

    private function refundPaid(CouponParticipationRequest $request, int $adminUserId, ?string $note): void
    {
        $invitation = $request->invitation;
        $fee = $this->money->of((string) $request->offered_fee, $invitation->currency);

        if ($request->payment_method === 'bank_transfer') {
            $this->ledger->post("coupon-participation:{$request->id}:refund", 'coupon_participation_refund', (new Posting)
                ->debit(AccountRef::platform(LedgerAccountCode::AdRevenue), $fee)
                ->credit(AccountRef::platform(LedgerAccountCode::BankClearing), $fee), ActorType::Admin, $adminUserId, $request, 'Coupon participation refund');
        } else {
            $this->billing->refund($request->participant_type, (int) $request->participant_id, $fee, VendorBalanceItem::TYPE_COUPON_PARTICIPATION_REFUND, $request, "coupon-participation:{$request->id}:refund", "Coupon participation refund: {$invitation->title}");
        }

        $this->machine->move($request, ParticipationRequestStatus::Refunded, ActorType::Admin, $adminUserId, $note, ['decision_note' => $note]);
    }

    private function seatsTaken(CouponParticipationInvitation $invitation): int
    {
        return $invitation->requests()->whereIn('status', [ParticipationRequestStatus::Paid, ParticipationRequestStatus::ApprovedPendingPayment])->count();
    }

    private function paidCount(CouponParticipationInvitation $invitation): int
    {
        return $invitation->requests()->where('status', ParticipationRequestStatus::Paid)->count();
    }

    private function closeIfFull(CouponParticipationInvitation $invitation): void
    {
        if ($invitation->status === ParticipationInvitationStatus::Open && $this->paidCount($invitation) >= $invitation->capacity) {
            $this->machine->move($invitation, ParticipationInvitationStatus::Closed, ActorType::System, null, 'Capacity reached');
        }
    }
}
