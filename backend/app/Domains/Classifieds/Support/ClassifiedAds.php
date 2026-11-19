<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedAdMedia;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use App\Shared\Settings\VerticalSettings;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Post-ad wizard writes (MP v2 §14b): category → attributes → photos /
 * short video → location → price; drafts are editable until submitted.
 * A business shop is the owner's own classified vendor; a broker ad is the
 * owner's own broker profile. Owner media go to the public disk.
 */
class ClassifiedAds
{
    public const DISK = 'public';

    public function __construct(
        private ClassifiedSpecs $specs,
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private VerticalSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $ownerUserId, array $data, ActorType $actor = ActorType::Customer, ?Vendor $shop = null, ?MarketerProfile $broker = null): ClassifiedAd
    {
        $category = ClassifiedCategory::query()->where('is_active', true)->find($data['category_id'] ?? 0)
            ?? throw new TransitionRefused(__('Choose an open category.'));

        return DB::connection($category->getConnectionName())->transaction(function () use ($ownerUserId, $data, $actor, $shop, $broker, $category): ClassifiedAd {
            $ad = new ClassifiedAd([
                'classified_category_id' => $category->id,
                'owner_user_id' => $ownerUserId,
                'vendor_id' => $shop?->id,
                'marketer_profile_id' => $broker?->id,
                'currency' => $this->countryContext->current()->currency,
                'number' => 'CL-'.uniqid(),
            ]);
            $ad->forceFill(['status' => ClassifiedAdStatus::Draft]);
            $this->fill($ad, $category, $data);
            $ad->save();
            $ad->forceFill(['number' => 'CL-'.$this->countryContext->current()->iso2.'-'.str_pad((string) $ad->id, 7, '0', STR_PAD_LEFT)])->save();
            $this->afterSave($ad, $data);
            $this->machine->created($ad, $actor, $ownerUserId);

            return $ad->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClassifiedAd $ad, array $data): ClassifiedAd
    {
        if (! in_array($ad->status, [ClassifiedAdStatus::Draft, ClassifiedAdStatus::PendingContract], true)) {
            throw new TransitionRefused($ad->status === ClassifiedAdStatus::Rejected
                ? __('A rejected ad cannot be edited. Copy it into a new draft (POST ads/{number}/duplicate) and submit that.')
                : __('Only a draft can be edited. Live ads go back to review when edited.'));
        }

        $category = isset($data['category_id']) ? (ClassifiedCategory::query()->where('is_active', true)->find($data['category_id']) ?? throw new TransitionRefused(__('Choose an open category.'))) : $ad->category;

        return DB::connection($ad->getConnectionName())->transaction(function () use ($ad, $data, $category): ClassifiedAd {
            $ad->classified_category_id = $category->id;
            $ad->setRelation('category', $category);
            $this->fill($ad, $category, $data + ['specs' => $ad->specs ?? []]);
            $ad->save();
            $this->afterSave($ad, $data);

            if ($ad->status === ClassifiedAdStatus::PendingContract) {
                $this->machine->move($ad, ClassifiedAdStatus::Draft, ActorType::Customer, $ad->owner_user_id, 'Edited');
            }

            return $ad->refresh();
        });
    }

    /**
     * A new draft copied from one of the owner's closed ads (G-W24): a
     * rejected ad is final (lifecycle §5.1), so the owner fixes and submits
     * the copy instead — same category, fields, specs, property details and
     * photos/video (files copied); the original keeps its history.
     */
    public function duplicate(ClassifiedAd $ad, ActorType $actor = ActorType::Customer): ClassifiedAd
    {
        if (! in_array($ad->status, [ClassifiedAdStatus::Rejected, ClassifiedAdStatus::Expired, ClassifiedAdStatus::Archived, ClassifiedAdStatus::Sold], true)) {
            throw new TransitionRefused(__('Only a rejected, expired, archived or sold ad can be copied into a new draft.'));
        }

        $ad->loadMissing(['property', 'media', 'vendor', 'marketer']);
        $data = $ad->only(['kind', 'title', 'description', 'is_negotiable', 'city', 'area', 'latitude', 'longitude', 'contact_phone']) + [
            'category_id' => $ad->classified_category_id,
            'price' => $ad->price === null ? null : (string) $ad->price,
            'specs' => $ad->specs ?? [],
        ];

        if ($ad->property !== null) {
            $data['property'] = collect($ad->property->only(['purpose', 'property_type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing', 'rent_period', 'permit_number',
                'permit_authority', 'permit_expires_on', 'completion_on', 'developer', 'payment_plan', 'features']))->all();
        }

        $copy = $this->create((int) $ad->owner_user_id, $data, $actor, $ad->vendor, $ad->marketer);
        $disk = Storage::disk(self::DISK);

        foreach ($ad->media->sortBy('sort_order') as $media) {
            if (! $disk->exists($media->path)) {
                continue;
            }

            $path = $this->folder($copy).'/'.basename($media->path);
            $disk->copy($media->path, $path);
            $copy->media()->create(['type' => $media->type, 'path' => $path, 'duration_seconds' => $media->duration_seconds, 'sort_order' => $media->sort_order]);
        }

        return $copy->refresh();
    }

    public function addPhoto(ClassifiedAd $ad, UploadedFile $file): ClassifiedAdMedia
    {
        $this->assertMediaEditable($ad);
        $max = $ad->category->photoLimits()['max_photos'];

        if ($ad->media()->where('type', 'photo')->count() >= $max) {
            throw new TransitionRefused(__('An ad can have at most :count photos.', ['count' => $max]));
        }

        return $ad->media()->create([
            'type' => 'photo',
            'path' => $file->store($this->folder($ad), self::DISK),
            'sort_order' => (int) $ad->media()->max('sort_order') + 1,
        ]);
    }

    public function addVideo(ClassifiedAd $ad, UploadedFile $file, int $durationSeconds): ClassifiedAdMedia
    {
        $this->assertMediaEditable($ad);

        if (! $ad->category->allows_video) {
            throw new TransitionRefused(__('This category does not take videos.'));
        }

        if ($durationSeconds < 1 || $durationSeconds > $this->settings->int('classified.video_max_seconds')) {
            throw new TransitionRefused(__('The video must be at most :seconds seconds.', ['seconds' => $this->settings->int('classified.video_max_seconds')]));
        }

        if ($ad->media()->where('type', 'video')->exists()) {
            throw new TransitionRefused(__('An ad has one short video.'));
        }

        return $ad->media()->create([
            'type' => 'video',
            'path' => $file->store($this->folder($ad), self::DISK),
            'duration_seconds' => $durationSeconds,
            'sort_order' => 0,
        ]);
    }

    public function removeMedia(ClassifiedAd $ad, int $mediaId): void
    {
        $this->assertMediaEditable($ad);
        $media = $ad->media()->findOrFail($mediaId);
        Storage::disk(self::DISK)->delete($media->path);
        $media->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(ClassifiedAd $ad, ClassifiedCategory $category, array $data): void
    {
        $ad->fill(array_intersect_key($data, array_flip(['kind', 'title', 'description', 'is_negotiable', 'city', 'area', 'latitude', 'longitude', 'contact_phone'])));
        $ad->kind = in_array($ad->kind, ['offer', 'wanted'], true) ? $ad->kind : 'offer';

        if (array_key_exists('price', $data)) {
            $ad->price = $data['price'] === null || $data['price'] === '' ? null : $this->money->toStorage($this->money->of((string) $data['price'], $ad->currency));
        }

        $ad->specs = $this->specs->normalize($category, (array) ($data['specs'] ?? []), strict: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function afterSave(ClassifiedAd $ad, array $data): void
    {
        $ad->load('category');
        $this->specs->index($ad);

        if (isset($data['property']) && is_array($data['property'])) {
            $ad->property()->updateOrCreate([], array_intersect_key($data['property'], array_flip([
                'purpose', 'property_type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing', 'rent_period', 'permit_number',
                'permit_authority', 'permit_expires_on', 'completion_on', 'developer', 'payment_plan', 'features',
            ])));
        }
    }

    private function assertMediaEditable(ClassifiedAd $ad): void
    {
        if (! in_array($ad->status, [ClassifiedAdStatus::Draft, ClassifiedAdStatus::PendingContract], true)) {
            throw new TransitionRefused(__('Media can be changed while the ad is a draft.'));
        }
    }

    private function folder(ClassifiedAd $ad): string
    {
        return 'classifieds/'.$this->countryContext->current()->code.'/'.$ad->number;
    }
}
