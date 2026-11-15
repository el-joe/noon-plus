<?php

namespace App\Domains\RealEstate\Support;

use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Enums\LeadStatus;
use App\Domains\RealEstate\Models\BrokerLead;
use App\Domains\RealEstate\Models\BrokerLeadFollowUp;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\RealEstate\Models\SpecialRequest;
use App\Models\User;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonInterface;

/**
 * Broker lead pipeline (spec §5.7, Figma 104:21867): leads come from
 * inquiries on the broker's ads, claimed special requests, viewing requests
 * or are added by hand; one lead per source; stages move through
 * LeadStatus with history; follow-ups keep `next_follow_up_at` current.
 */
class BrokerLeads
{
    public function __construct(private StateMachine $machine) {}

    public function fromInquiry(ClassifiedThread $thread, int $marketerProfileId): BrokerLead
    {
        return $this->open($marketerProfileId, 'inquiry', $thread->id, [
            'classified_ad_id' => $thread->classified_ad_id,
            'customer_user_id' => $thread->customer_user_id,
            'name' => $thread->contact_name,
            'email' => $thread->contact_email,
            'phone' => $thread->contact_phone,
        ]);
    }

    public function fromSpecialRequest(SpecialRequest $request, int $marketerProfileId): BrokerLead
    {
        $user = User::query()->find($request->customer_user_id);

        return $this->open($marketerProfileId, 'special_request', $request->id, [
            'customer_user_id' => $request->customer_user_id,
            'name' => (string) $user?->name,
            'email' => $user?->email,
            'phone' => $user?->phone,
            'budget' => $request->budget_max,
            'notes' => $request->details,
        ]);
    }

    public function fromViewing(PropertyViewing $viewing, int $marketerProfileId): BrokerLead
    {
        return $this->open($marketerProfileId, 'viewing', $viewing->id, [
            'classified_ad_id' => $viewing->classified_ad_id,
            'customer_user_id' => $viewing->customer_user_id,
            'name' => $viewing->contact_name,
            'email' => $viewing->contact_email,
            'phone' => $viewing->contact_phone,
            'notes' => $viewing->note,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function manual(int $marketerProfileId, array $data): BrokerLead
    {
        return $this->open($marketerProfileId, 'manual', null, $data);
    }

    public function move(BrokerLead $lead, LeadStatus $to, int $userId, ?string $reason = null): BrokerLead
    {
        if ($to === LeadStatus::Lost && blank($reason)) {
            throw new TransitionRefused(__('Say why the lead was lost.'));
        }

        return $this->machine->move($lead, $to, ActorType::Marketer, $userId, $reason, $to === LeadStatus::Lost ? ['lost_reason' => mb_substr((string) $reason, 0, 255), 'next_follow_up_at' => null] : ($to === LeadStatus::Won ? ['next_follow_up_at' => null] : []));
    }

    public function addFollowUp(BrokerLead $lead, string $channel, CarbonInterface $dueAt, ?string $note = null): BrokerLeadFollowUp
    {
        if (! $lead->status->isOpen()) {
            throw new TransitionRefused(__('Follow-ups are for open leads.'));
        }

        $followUp = $lead->followUps()->create(['channel' => $channel, 'due_at' => $dueAt, 'note' => $note]);
        $this->refreshNext($lead);

        return $followUp;
    }

    public function completeFollowUp(BrokerLeadFollowUp $followUp): void
    {
        $followUp->forceFill(['done_at' => now()])->save();
        $this->refreshNext($followUp->lead);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function open(int $marketerProfileId, string $source, ?int $sourceId, array $attributes): BrokerLead
    {
        if ($sourceId !== null) {
            $existing = BrokerLead::query()->where(['marketer_profile_id' => $marketerProfileId, 'source' => $source, 'source_id' => $sourceId])->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $lead = new BrokerLead(['marketer_profile_id' => $marketerProfileId, 'source' => $source, 'source_id' => $sourceId] + $attributes + ['name' => __('Unknown')]);
        $lead->forceFill(['status' => LeadStatus::New])->save();
        $this->machine->created($lead, ActorType::System, null, $source);

        return $lead;
    }

    private function refreshNext(BrokerLead $lead): void
    {
        $lead->forceFill(['next_follow_up_at' => $lead->followUps()->whereNull('done_at')->min('due_at')])->save();
    }
}
