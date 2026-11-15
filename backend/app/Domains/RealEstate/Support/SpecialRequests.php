<?php

namespace App\Domains\RealEstate\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Enums\SpecialRequestStatus;
use App\Domains\RealEstate\Models\SpecialRequest;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * Special requests (SRS §11.13, lifecycle catalogue §9.1). A broker
 * "starts" an open request with one conditional UPDATE (`status = open`),
 * so of two brokers pressing at once exactly one wins; the winner gets a
 * lead.
 */
class SpecialRequests
{
    public function __construct(private StateMachine $machine, private BrokerLeads $leads, private CountryContext $countryContext) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $customerUserId, array $data): SpecialRequest
    {
        $request = new SpecialRequest($data + ['customer_user_id' => $customerUserId, 'number' => 'SR-'.uniqid()]);
        $request->forceFill(['status' => SpecialRequestStatus::Open])->save();
        $request->forceFill(['number' => 'SR-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT)])->save();
        $this->machine->created($request, ActorType::Customer, $customerUserId);

        return $request;
    }

    public function claim(SpecialRequest $request, MarketerProfile $broker, int $userId): SpecialRequest
    {
        if ($broker->global_status !== MarketerStatus::Active || ! in_array('broker', $broker->job_types ?? [], true)) {
            throw new TransitionRefused(__('Only an active broker can start a request.'));
        }

        return DB::connection($request->getConnectionName())->transaction(function () use ($request, $broker, $userId): SpecialRequest {
            $claimed = SpecialRequest::query()->whereKey($request->id)->where('status', SpecialRequestStatus::Open)->update([
                'status' => SpecialRequestStatus::InProgress,
                'claimed_by_marketer_profile_id' => $broker->id,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

            if ($claimed !== 1) {
                throw new TransitionRefused(__('Another broker already started this request.'));
            }

            $request->refresh();
            $this->machine->created($request, ActorType::Marketer, $userId, 'Claimed by broker #'.$broker->id);
            $this->leads->fromSpecialRequest($request, $broker->id);

            return $request;
        });
    }

    public function finish(SpecialRequest $request, MarketerProfile $broker, SpecialRequestStatus $to, int $userId): SpecialRequest
    {
        if ($request->claimed_by_marketer_profile_id !== $broker->id) {
            throw new TransitionRefused(__('This request is handled by another broker.'));
        }

        return $this->machine->move($request, $to, ActorType::Marketer, $userId);
    }

    public function cancel(SpecialRequest $request, int $customerUserId): SpecialRequest
    {
        return $this->machine->move($request, SpecialRequestStatus::Cancelled, ActorType::Customer, $customerUserId);
    }
}
