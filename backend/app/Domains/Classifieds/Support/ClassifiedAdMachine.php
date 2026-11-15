<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\SignatureMethod;
use App\Domains\Contracts\Enums\SignerType;
use App\Domains\Contracts\Exceptions\ContractNotConfigured;
use App\Domains\Contracts\Support\ContractResolver;
use App\Domains\Contracts\Support\ContractSigner;
use App\Domains\Contracts\Support\ResolvedContract;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Support\Facades\DB;

/**
 * ClassifiedAdMachine (SRS §12.1, lifecycle catalogue §5.1). Submission
 * checks the ad (title, price or negotiable, photo for offers, required
 * attributes, permit for property, active broker / shop) and then the
 * category contract (FR-CLS-1): signed → pending_review, not yet signed →
 * pending_contract (signing moves it on), required but not configured →
 * refused. Only an admin approves (FR-CLS-2); approval starts the listing
 * period. Mark-sold records the final price and the marketer who brought
 * the buyer (must have referred an inquiry on the ad).
 */
class ClassifiedAdMachine
{
    public function __construct(
        private StateMachine $machine,
        private ContractResolver $resolver,
        private ContractSigner $signer,
        private CountryContext $countryContext,
        private ClassifiedSpecs $specs,
        private VerticalSettings $settings,
        private MoneyFactory $money,
    ) {}

    public function submit(ClassifiedAd $ad, ActorType $actor, ?int $userId): ClassifiedAd
    {
        if ($ad->status !== ClassifiedAdStatus::Draft) {
            throw new TransitionRefused(__('Only a draft can be submitted.'));
        }

        $this->assertComplete($ad);
        $contract = $this->sellerContract($ad);

        if ($contract !== null && ! $this->hasSigned($ad, $contract)) {
            return $this->machine->move($ad, ClassifiedAdStatus::PendingContract, $actor, $userId, 'Contract required before review');
        }

        return $this->machine->move($ad, ClassifiedAdStatus::PendingReview, $actor, $userId, null, ['submitted_at' => now()]);
    }

    /**
     * FR-CLS-1: sign the category contract (click-wrap) and move on to review.
     */
    public function signContract(ClassifiedAd $ad, string $fullName, ActorType $actor, int $userId, ?string $ip = null, ?string $userAgent = null): ClassifiedAd
    {
        if ($ad->status !== ClassifiedAdStatus::PendingContract) {
            throw new TransitionRefused(__('This ad is not waiting for a contract.'));
        }

        $contract = $this->sellerContract($ad) ?? throw new TransitionRefused(__('No contract is needed for this category.'));
        $signer = AdSigner::of($ad);
        $signature = $this->signer->sign($contract->version, $signer->type, $signer->id, $userId, $fullName, SignatureMethod::Click, $ip, $userAgent, $contract->subjectType, $contract->subjectId, app()->getLocale(), ['store_name' => $ad->vendor?->store_name]);

        return $this->machine->move($ad, ClassifiedAdStatus::PendingReview, $actor, $userId, 'Contract signed', ['submitted_at' => now(), 'contract_signature_id' => $signature->id]);
    }

    /**
     * The contract the ad's category requires (null when none is required).
     *
     * @throws TransitionRefused when the category requires one that is not configured
     */
    public function sellerContract(ClassifiedAd $ad): ?ResolvedContract
    {
        if (! $ad->category->needsContract()) {
            return null;
        }

        try {
            return $this->resolver->forClassifiedCategory(ContractKind::SellerCategory, $this->countryContext->current(), $ad->category);
        } catch (ContractNotConfigured $missing) {
            throw new TransitionRefused($missing->getMessage());
        }
    }

    public function approve(ClassifiedAd $ad, int $adminUserId): ClassifiedAd
    {
        $days = (int) ($ad->category->inherited('listing_duration_days') ?? $this->settings->int('classified.listing_duration_days'));

        return $this->machine->move($ad, ClassifiedAdStatus::Active, ActorType::Admin, $adminUserId, 'Approved', [
            'approved_at' => now(),
            'bumped_at' => now(),
            'expires_at' => now()->addDays($days),
            'reminders_sent' => [],
            'rejection_reason' => null,
        ]);
    }

    public function reject(ClassifiedAd $ad, int $adminUserId, string $reason): ClassifiedAd
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new TransitionRefused(__('A rejection needs a reason for the seller.'));
        }

        return $this->machine->move($ad, ClassifiedAdStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    public function pause(ClassifiedAd $ad, ActorType $actor, int $userId): ClassifiedAd
    {
        return $this->machine->move($ad, ClassifiedAdStatus::Paused, $actor, $userId);
    }

    public function resume(ClassifiedAd $ad, ActorType $actor, int $userId): ClassifiedAd
    {
        if ($ad->status === ClassifiedAdStatus::Paused && $ad->expires_at !== null && $ad->expires_at->isPast()) {
            return $this->machine->move($ad, ClassifiedAdStatus::Expired, ActorType::System, null, 'Listing period ended while paused');
        }

        return $this->machine->move($ad, ClassifiedAdStatus::Active, $actor, $userId, 'Resumed');
    }

    public function archive(ClassifiedAd $ad, ActorType $actor, ?int $userId, ?string $reason = null): ClassifiedAd
    {
        return $this->machine->move($ad, ClassifiedAdStatus::Archived, $actor, $userId, $reason);
    }

    /**
     * Mark sold (SRS §11.12): final price, optional marketer who brought the
     * buyer (one who referred an inquiry on this ad) and a buyer note.
     */
    public function markSold(ClassifiedAd $ad, ActorType $actor, int $userId, string $finalPrice, ?int $marketerProfileId = null, ?string $buyerNote = null): ClassifiedAd
    {
        if (! is_numeric($finalPrice) || bccomp($finalPrice, '0', 4) <= 0) {
            throw new TransitionRefused(__('Enter the final sold price.'));
        }

        if ($marketerProfileId !== null && ! array_key_exists($marketerProfileId, $this->referrers($ad))) {
            throw new TransitionRefused(__('Choose a marketer who referred a buyer to this ad.'));
        }

        return $this->machine->move($ad, ClassifiedAdStatus::Sold, $actor, $userId, 'Marked sold', [
            'sold_price' => $this->money->toStorage($this->money->of($finalPrice, $ad->currency)),
            'sold_marketer_profile_id' => $marketerProfileId,
            'sold_buyer_note' => $buyerNote === null ? null : mb_substr($buyerNote, 0, 255),
            'sold_at' => now(),
        ], ['marketer_profile_id' => $marketerProfileId]);
    }

    /**
     * Un-mark a sale: the attribution is cleared (no commission was paid on classifieds yet).
     */
    public function unmarkSold(ClassifiedAd $ad, ActorType $actor, int $userId): ClassifiedAd
    {
        return $this->machine->move($ad, ClassifiedAdStatus::Active, $actor, $userId, 'Sale un-marked', [
            'sold_price' => null, 'sold_marketer_profile_id' => null, 'sold_buyer_note' => null, 'sold_at' => null,
        ]);
    }

    /**
     * Attribution picker: marketers who referred inquiries on the ad with
     * how many each.
     *
     * @return array<int, array{name: string, inquiries: int}>
     */
    public function referrers(ClassifiedAd $ad): array
    {
        $counts = ClassifiedThread::query()->where('classified_ad_id', $ad->id)->whereNotNull('marketer_profile_id')
            ->selectRaw('marketer_profile_id, count(*) as inquiries')->groupBy('marketer_profile_id')->pluck('inquiries', 'marketer_profile_id');
        $names = MarketerProfile::query()->whereKey($counts->keys())->pluck('display_name', 'id');

        return $counts->mapWithKeys(fn ($count, $id): array => [(int) $id => ['name' => (string) $names->get($id), 'inquiries' => (int) $count]])->all();
    }

    /**
     * Renew an expired ad: free renewal (category or country renewal price
     * 0) puts it back live for a new period; otherwise a republish package
     * must be bought.
     */
    public function renew(ClassifiedAd $ad, ActorType $actor, int $userId): ClassifiedAd
    {
        if ($ad->status !== ClassifiedAdStatus::Expired) {
            throw new TransitionRefused(__('Only an expired ad can be renewed.'));
        }

        $price = (string) ($ad->category->inherited('renewal_price') ?? $this->settings->string('classified.renewal_price'));

        if (bccomp($price, '0', 4) > 0) {
            throw new TransitionRefused(__('Renewing this ad needs a republish package.'));
        }

        return $this->relist($ad, $actor, $userId, 'Renewed (free)');
    }

    /**
     * Back live for a new listing period (renewal or republish package).
     */
    public function relist(ClassifiedAd $ad, ActorType $actor, ?int $userId, string $reason): ClassifiedAd
    {
        $days = (int) ($ad->category->inherited('listing_duration_days') ?? $this->settings->int('classified.listing_duration_days'));
        $attributes = ['expires_at' => now()->addDays($days), 'bumped_at' => now(), 'reminders_sent' => []];

        if ($ad->status === ClassifiedAdStatus::Active) {
            $ad->forceFill($attributes)->save();

            return $ad;
        }

        return $this->machine->move($ad, ClassifiedAdStatus::Active, $actor, $userId, $reason, $attributes);
    }

    public function expire(ClassifiedAd $ad): ClassifiedAd
    {
        return $this->machine->move($ad, ClassifiedAdStatus::Expired, ActorType::System, null, 'Listing period ended');
    }

    private function hasSigned(ClassifiedAd $ad, ResolvedContract $contract): bool
    {
        $signer = AdSigner::of($ad);

        return $this->signer->hasCurrent($contract->version, $signer->type, $signer->id, $contract->subjectType, $contract->subjectId);
    }

    private function assertComplete(ClassifiedAd $ad): void
    {
        if (mb_strlen(trim($ad->title)) < 5) {
            throw new TransitionRefused(__('Give the ad a title of at least 5 characters.'));
        }

        if (! $ad->category->is_active) {
            throw new TransitionRefused(__('This category is not open for ads.'));
        }

        if ($ad->kind === 'offer') {
            if (! $ad->is_negotiable && ($ad->price === null || bccomp((string) $ad->price, '0', 4) <= 0)) {
                throw new TransitionRefused(__('Set a price or mark it negotiable.'));
            }

            if (! $ad->media()->where('type', 'photo')->exists()) {
                throw new TransitionRefused(__('Add at least one photo.'));
            }
        }

        $this->specs->normalize($ad->category, $ad->specs ?? [], strict: true);

        if ($ad->category->inherited('vertical') === 'property') {
            $property = $ad->property ?? throw new TransitionRefused(__('Add the property details.'));

            if ((bool) $this->settings->get('property.verification_required') && blank($property->permit_number)) {
                throw new TransitionRefused(__('A property ad needs its advertising permit number.'));
            }

            if ($property->permit_expires_on !== null && $property->permit_expires_on->isPast()) {
                throw new TransitionRefused(__('The advertising permit has expired.'));
            }
        }

        if ($ad->vendor_id !== null && ! $ad->vendor?->isActive()) {
            throw new TransitionRefused(__('The shop account is not active.'));
        }

        if ($ad->marketer_profile_id !== null) {
            $this->assertBrokerCanPublish($ad->marketer);
        }
    }

    /**
     * A broker publishes only while active, with the broker job type and the
     * current Broker agreement signed (when one is published in the country).
     */
    public function assertBrokerCanPublish(?MarketerProfile $marketer): void
    {
        if ($marketer === null || $marketer->global_status !== MarketerStatus::Active || ! in_array('broker', $marketer->job_types ?? [], true)) {
            throw new TransitionRefused(__('Only an active broker can publish property ads.'));
        }

        $agreement = $this->resolver->current(ContractKind::Broker, $this->countryContext->current());

        if ($agreement !== null && ! $this->signer->hasCurrent($agreement, SignerType::Marketer, $marketer->id)) {
            throw new TransitionRefused(__('Sign the broker agreement first.'));
        }
    }

    /**
     * Expire live and paused ads past their period (classified expiry job).
     */
    public function expireDue(): int
    {
        $count = 0;

        ClassifiedAd::query()
            ->whereIn('status', [ClassifiedAdStatus::Active, ClassifiedAdStatus::Paused])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (ClassifiedAd $ad) use (&$count): void {
                DB::connection($ad->getConnectionName())->transaction(fn () => $this->expire($ad));
                $count++;
            });

        return $count;
    }
}
