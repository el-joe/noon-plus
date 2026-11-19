<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Enums\ThreadStatus;
use App\Domains\Classifieds\Exceptions\CustomerContractRequired;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedMessage;
use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Classifieds\Models\PhoneReveal;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\RealEstate\Support\BrokerLeads;
use App\Models\User;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Validation\CountryPhone;
use Illuminate\Support\Facades\DB;

/**
 * Inquiries and chat on classified ads (FR-CLS-4, lifecycle catalogue §5.4).
 * Every inquiry carries the customer's contact details (owner decision
 * WP6, no payment). The customer purchase contract assigned to the ad's
 * category (or a parent) must be accepted before an inquiry, a phone reveal or chat; a
 * guest is answered 409 `customer_contract_required` (sign in to accept).
 * A thread is stamped with the referring affiliate / broker (`ref` = their
 * boutique slug) for the mark-sold attribution picker; an inquiry on a
 * broker's ad opens a lead in the broker's pipeline.
 */
class ClassifiedInquiries
{
    public function __construct(
        private ContractResolver $resolver,
        private ContractSigner $signer,
        private CountryContext $countryContext,
        private BrokerLeads $leads,
        private Emails $emails,
    ) {}

    /**
     * @throws CustomerContractRequired
     */
    public function assertContract(ClassifiedAd $ad, ?int $customerUserId): void
    {
        $contract = $this->resolver->forClassifiedCategory(ContractKind::CustomerPurchase, $this->countryContext->current(), $ad->category);

        if ($contract === null) {
            return;
        }

        if ($customerUserId === null || ! $this->signer->hasCurrent($contract->version, SignerType::Customer, $customerUserId)) {
            throw new CustomerContractRequired($contract->version, $this->countryContext->current()->marketSlug('en'));
        }
    }

    /**
     * An inquiry is a contact-details submission (owner decision WP6): name,
     * email and a phone of the country are required from everyone (the apps
     * prefill a signed-in customer's own); no payment. The seller is mailed
     * and the customer gets a confirmation.
     *
     * @param  array{name: string, email: string, phone: string}  $contact
     */
    public function start(ClassifiedAd $ad, ?User $customer, string $body, array $contact, ?string $referrer = null): ClassifiedThread
    {
        $this->assertOpen($ad);

        if ($customer !== null && $customer->id === $ad->owner_user_id) {
            throw new TransitionRefused(__('This is your own ad.'));
        }

        $phone = CountryPhone::normalize((string) ($contact['phone'] ?? '')) ?? throw new TransitionRefused(__('Enter a valid phone number.'));

        if (blank($contact['name'] ?? null) || blank($contact['email'] ?? null)) {
            throw new TransitionRefused(__('Leave your name, email and phone for the seller.'));
        }

        $this->assertContract($ad, $customer?->id);
        $marketer = $this->referrer($referrer, $ad);

        $isNew = false;
        $thread = DB::connection($ad->getConnectionName())->transaction(function () use ($ad, $customer, $body, $contact, $phone, $marketer, $referrer, &$isNew): ClassifiedThread {
            $thread = $customer === null ? null : ClassifiedThread::query()->where('classified_ad_id', $ad->id)->where('customer_user_id', $customer->id)->where('status', '!=', ThreadStatus::Closed)->first();
            $isNew = $thread === null;

            if ($isNew) {
                $thread = new ClassifiedThread([
                    'classified_ad_id' => $ad->id,
                    'customer_user_id' => $customer?->id,
                    'seller_user_id' => $ad->owner_user_id,
                    'contact_name' => mb_substr(trim((string) $contact['name']), 0, 120),
                    'contact_email' => mb_strtolower(trim((string) $contact['email'])),
                    'contact_phone' => $phone,
                    'marketer_profile_id' => $marketer?->id,
                    'referral_code' => $marketer === null ? null : mb_substr((string) $referrer, 0, 16),
                ]);
                $thread->forceFill(['status' => ThreadStatus::New])->save();

                if ($ad->marketer_profile_id !== null) {
                    $this->leads->fromInquiry($thread, (int) $ad->marketer_profile_id);
                }
            }

            $this->append($thread, $customer?->id, 'buyer', $body);

            return $thread;
        });

        if ($isNew) {
            $owner = User::query()->find($ad->owner_user_id);

            if ($owner !== null) {
                $this->emails->send('classified.new_message', $owner, [
                    'ad_title' => (string) $ad->title,
                    'from' => $thread->contact_name,
                    'message' => mb_substr($body, 0, 500),
                ], [
                    'entity' => $thread->messages()->latest('id')->first() ?? $thread,
                    'url' => $this->conversationLink($owner),
                    'blocks' => [['type' => 'facts', 'rows' => [['Name', $thread->contact_name], ['Email', $thread->contact_email], ['Phone', $thread->contact_phone]]]],
                ]);
            }

            $this->emails->send('classified.inquiry_sent', $thread->contact_email, ['ad_title' => (string) $ad->title], [
                'entity' => $thread,
                'locale' => app()->getLocale(),
                'name' => $thread->contact_name,
            ]);
        }

        return $thread->refresh();
    }

    public function reply(ClassifiedThread $thread, int $userId, string $body): ClassifiedMessage
    {
        if ($thread->status === ThreadStatus::Closed) {
            throw new TransitionRefused(__('This conversation is closed.'));
        }

        $isSeller = $userId === $thread->seller_user_id;

        if ($isSeller && $thread->status === ThreadStatus::New) {
            $thread->forceFill(['status' => ThreadStatus::Contacted])->save();
        }

        $message = $this->append($thread, $userId, $isSeller ? 'seller' : 'buyer', $body);
        $recipient = User::query()->find($isSeller ? $thread->customer_user_id : $thread->seller_user_id);

        // Each later message emails the other side (a guest buyer only reads replies in chat).
        if ($recipient !== null) {
            $thread->loadMissing('ad');
            $this->emails->send('classified.new_message', $recipient, [
                'ad_title' => (string) $thread->ad?->title,
                'from' => $isSeller ? EmailValue::t('the seller') : (string) $thread->contact_name,
                'message' => mb_substr($body, 0, 500),
            ], ['entity' => $message, 'url' => $this->conversationLink($recipient)]);
        }

        return $message;
    }

    public function close(ClassifiedThread $thread): ClassifiedThread
    {
        if (! in_array(ThreadStatus::Closed, $thread->status->allowedNext(), true)) {
            throw new TransitionRefused(__('This conversation is already closed.'));
        }

        $thread->forceFill(['status' => ThreadStatus::Closed])->save();

        return $thread;
    }

    /**
     * Mark the other side's messages as read for this reader.
     */
    public function markRead(ClassifiedThread $thread, int $readerUserId): void
    {
        $thread->messages()->whereNull('read_at')->where(fn ($query) => $query->whereNull('sender_user_id')->orWhere('sender_user_id', '!=', $readerUserId))->update(['read_at' => now()]);
    }

    /**
     * Phone reveal (MP v2 §14b): signed-in customers only, contract gate,
     * every reveal logged.
     */
    public function revealPhone(ClassifiedAd $ad, User $customer, ?string $ip, ?string $userAgent): string
    {
        $this->assertOpen($ad);
        $this->assertContract($ad, $customer->id);
        $phone = $ad->contact_phone ?? User::query()->whereKey($ad->owner_user_id)->value('phone');

        if (blank($phone)) {
            throw new TransitionRefused(__('The seller prefers chat.'));
        }

        PhoneReveal::query()->create([
            'classified_ad_id' => $ad->id,
            'customer_user_id' => $customer->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        return (string) $phone;
    }

    /**
     * The storefront Open Souq inbox, in the recipient's language.
     */
    private function conversationLink(User $recipient): string
    {
        return app(EmailLinks::class)->storefront('account/inquiries', $recipient->preferredLocale());
    }

    private function append(ClassifiedThread $thread, ?int $senderUserId, string $role, string $body): ClassifiedMessage
    {
        $message = $thread->messages()->create(['sender_user_id' => $senderUserId, 'sender_role' => $role, 'body' => mb_substr(trim($body), 0, 4000)]);
        $thread->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    private function assertOpen(ClassifiedAd $ad): void
    {
        if ($ad->status !== ClassifiedAdStatus::Active) {
            throw new TransitionRefused(__('This ad is not live.'));
        }
    }

    private function referrer(?string $slug, ClassifiedAd $ad): ?MarketerProfile
    {
        if (blank($slug)) {
            return null;
        }

        $marketer = MarketerProfile::query()->where('slug', $slug)->where('global_status', MarketerStatus::Active)->first();

        if ($marketer === null || array_intersect(['affiliate', 'broker'], $marketer->job_types ?? []) === [] || $marketer->user_id === $ad->owner_user_id) {
            return null;
        }

        return $marketer;
    }
}
