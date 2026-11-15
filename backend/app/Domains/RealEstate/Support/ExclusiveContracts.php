<?php

namespace App\Domains\RealEstate\Support;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\RealEstate\Enums\ExclusiveStatus;
use App\Domains\RealEstate\Models\ExclusiveContract;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Exclusive contracts (SRS §11.13, lifecycle catalogue §8.4): the admin
 * grants a marketer exclusivity on an ad or a whole classified category for
 * a window, with a private file. Overlapping active grants on the same
 * subject are refused. The storefront shows the exclusive marketer; the ad
 * grant beats the category grant. Activation / expiry run daily (00:45).
 */
class ExclusiveContracts
{
    public const DISK = 'local';

    public function __construct(private StateMachine $machine) {}

    public function grant(MarketerProfile $marketer, string $subjectType, int $subjectId, CarbonInterface $startsAt, CarbonInterface $endsAt, int $adminUserId, ?UploadedFile $file = null): ExclusiveContract
    {
        if (! in_array($subjectType, [ExclusiveContract::SUBJECT_AD, ExclusiveContract::SUBJECT_CATEGORY], true) || $endsAt->lessThanOrEqualTo($startsAt)) {
            throw new TransitionRefused(__('Choose an ad or category and a valid date window.'));
        }

        $overlap = ExclusiveContract::query()->where(['subject_type' => $subjectType, 'subject_id' => $subjectId])
            ->whereIn('status', [ExclusiveStatus::Scheduled, ExclusiveStatus::Active])
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();

        if ($overlap) {
            throw new TransitionRefused(__('Another exclusive contract covers these dates.'));
        }

        $contract = new ExclusiveContract([
            'marketer_profile_id' => $marketer->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'granted_by_user_id' => $adminUserId,
            'file_path' => $file?->store('exclusive-contracts', self::DISK),
        ]);
        $contract->forceFill(['status' => $startsAt->isFuture() ? ExclusiveStatus::Scheduled : ExclusiveStatus::Active])->save();
        $this->machine->created($contract, ActorType::Admin, $adminUserId);

        return $contract;
    }

    public function revoke(ExclusiveContract $contract, int $adminUserId, string $reason): ExclusiveContract
    {
        return $this->machine->move($contract, ExclusiveStatus::Revoked, ActorType::Admin, $adminUserId, $reason, ['revoke_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * Daily (00:45): scheduled grants whose start passed become active,
     * grants whose end passed expire.
     *
     * @return array{activated: int, expired: int}
     */
    public function sync(): array
    {
        $expired = 0;
        $activated = 0;

        ExclusiveContract::query()->whereIn('status', [ExclusiveStatus::Scheduled, ExclusiveStatus::Active])->where('ends_at', '<=', now())
            ->each(function (ExclusiveContract $contract) use (&$expired): void {
                $this->machine->move($contract, ExclusiveStatus::Expired, ActorType::System);
                $expired++;
            });

        ExclusiveContract::query()->where('status', ExclusiveStatus::Scheduled)->where('starts_at', '<=', now())
            ->each(function (ExclusiveContract $contract) use (&$activated): void {
                $this->machine->move($contract, ExclusiveStatus::Active, ActorType::System);
                $activated++;
            });

        return ['activated' => $activated, 'expired' => $expired];
    }

    /**
     * The active exclusive marketer of an ad: the ad's own grant beats one on its category (or a parent).
     */
    public function activeFor(ClassifiedAd $ad): ?ExclusiveContract
    {
        $active = fn (string $type, array $ids) => ExclusiveContract::query()->where('subject_type', $type)->whereIn('subject_id', $ids)
            ->where('status', ExclusiveStatus::Active)->where('starts_at', '<=', now())->where('ends_at', '>', now())->with('marketer')->first();

        return $active(ExclusiveContract::SUBJECT_AD, [$ad->id])
            ?? $active(ExclusiveContract::SUBJECT_CATEGORY, array_map(fn ($category): int => $category->id, $ad->category->lineage()));
    }
}
