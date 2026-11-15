<?php

namespace App\Domains\Classifieds\Http\Controllers\Api;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Http\ClassifiedPresenter;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedFavourite;
use App\Domains\Classifieds\Models\ClassifiedMessage;
use App\Domains\Classifieds\Models\ClassifiedThread;
use App\Domains\Classifieds\Models\Follow;
use App\Domains\Classifieds\Models\SavedSearch;
use App\Domains\Classifieds\Support\ClassifiedInquiries;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Validation\ContactDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer side of classifieds: inquiries (guests too, FR-CLS-4), chat
 * threads for both parties, phone reveal (logged), favourites, saved
 * searches with alerts, and follows of shops / creators / brokers.
 */
class BuyerController extends Controller
{
    public function __construct(private ClassifiedInquiries $inquiries) {}

    public function inquire(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(ContactDetails::rules($request) + [
            'message' => ['required', 'string', 'min:2', 'max:4000'],
            'ref' => ['nullable', 'string', 'max:120'],
        ]);
        $ad = ClassifiedAd::query()->where('number', $number)->with('category')->firstOrFail();
        $thread = $this->inquiries->start($ad, $request->user('sanctum'), $data['message'], ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']], $data['ref'] ?? null);

        return ApiResponse::success(['thread_id' => $thread->id, 'status' => $thread->status->value], status: 201);
    }

    public function revealPhone(Request $request, string $number): JsonResponse
    {
        $ad = ClassifiedAd::query()->where('number', $number)->with('category')->firstOrFail();

        return ApiResponse::success(['phone' => $this->inquiries->revealPhone($ad, $request->user(), $request->ip(), $request->userAgent())]);
    }

    public function threads(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $threads = ClassifiedThread::query()->where(fn ($query) => $query->where('customer_user_id', $userId)->orWhere('seller_user_id', $userId))
            ->with('ad')->withCount(['messages as unread' => fn ($query) => $query->whereNull('read_at')->where(fn ($inner) => $inner->whereNull('sender_user_id')->orWhere('sender_user_id', '!=', $userId))])
            ->latest('last_message_at')->paginate(30);

        return ApiResponse::success(collect($threads->items())->map(fn (ClassifiedThread $thread): array => [
            'id' => $thread->id,
            'ad' => ['number' => $thread->ad->number, 'title' => $thread->ad->title],
            'role' => $thread->seller_user_id === $userId ? 'seller' : 'buyer',
            'status' => $thread->status->value,
            'unread' => (int) $thread->unread,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
        ])->all(), ['page' => $threads->currentPage(), 'last_page' => $threads->lastPage()]);
    }

    public function thread(Request $request, int $thread): JsonResponse
    {
        $thread = $this->ownThread($request, $thread);
        $this->inquiries->markRead($thread, $request->user()->id);

        return ApiResponse::success([
            'id' => $thread->id,
            'status' => $thread->status->value,
            'ad' => ['number' => $thread->ad->number, 'title' => $thread->ad->title],
            'messages' => $thread->messages()->orderBy('id')->get()->map(fn (ClassifiedMessage $message): array => [
                'id' => $message->id, 'from' => $message->sender_role, 'body' => $message->body, 'at' => $message->created_at?->toIso8601String(), 'read' => $message->read_at !== null,
            ])->all(),
        ]);
    }

    public function message(Request $request, int $thread): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:4000']])['body'];
        $thread = $this->ownThread($request, $thread);

        if ($thread->customer_user_id === $request->user()->id) {
            $this->inquiries->assertContract($thread->ad()->with('category')->firstOrFail(), $request->user()->id);
        }

        $message = $this->inquiries->reply($thread, $request->user()->id, $body);

        return ApiResponse::success(['id' => $message->id, 'from' => $message->sender_role], status: 201);
    }

    public function close(Request $request, int $thread): JsonResponse
    {
        return ApiResponse::success(['status' => $this->inquiries->close($this->ownThread($request, $thread))->status->value]);
    }

    public function favourites(Request $request): JsonResponse
    {
        $ids = ClassifiedFavourite::query()->where('customer_user_id', $request->user()->id)->pluck('classified_ad_id');
        $ads = ClassifiedAd::query()->whereKey($ids)->with(['media', 'category', 'vendor'])->get();

        return ApiResponse::success($ads->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad))->all());
    }

    public function favourite(Request $request, string $number): JsonResponse
    {
        $ad = ClassifiedAd::query()->where('number', $number)->where('status', ClassifiedAdStatus::Active)->firstOrFail();
        ClassifiedFavourite::query()->firstOrCreate(['customer_user_id' => $request->user()->id, 'classified_ad_id' => $ad->id]);

        return ApiResponse::success(['favourite' => true]);
    }

    public function unfavourite(Request $request, string $number): JsonResponse
    {
        $adId = ClassifiedAd::query()->where('number', $number)->value('id');
        ClassifiedFavourite::query()->where(['customer_user_id' => $request->user()->id, 'classified_ad_id' => $adId])->delete();

        return ApiResponse::success(['favourite' => false]);
    }

    public function savedSearches(Request $request): JsonResponse
    {
        return ApiResponse::success(SavedSearch::query()->where('customer_user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (SavedSearch $search): array => $search->only(['id', 'name', 'filters', 'alert_frequency', 'status']))->all());
    }

    public function saveSearch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'filters.q' => ['nullable', 'string', 'max:100'],
            'filters.category' => ['nullable', 'string', 'max:120'],
            'filters.kind' => ['nullable', 'in:offer,wanted'],
            'filters.city' => ['nullable', 'string', 'max:80'],
            'filters.min_price' => ['nullable', 'numeric'],
            'filters.max_price' => ['nullable', 'numeric'],
            'filters.purpose' => ['nullable', 'in:rent,sale,off_plan'],
            'filters.property_type' => ['nullable', 'string', 'max:24'],
            'filters.bedrooms_min' => ['nullable', 'integer'],
            'filters.verified' => ['nullable', 'boolean'],
            'filters.attr' => ['nullable', 'array'],
            'filters.attr.*' => ['nullable'],
            'alert_frequency' => ['sometimes', 'in:instant,daily'],
        ]);

        if (SavedSearch::query()->where('customer_user_id', $request->user()->id)->count() >= 50) {
            return ApiResponse::error('limit_reached', __('You can keep up to 50 saved searches.'), 422);
        }

        $search = SavedSearch::query()->create(['customer_user_id' => $request->user()->id, 'name' => $data['name'], 'filters' => $data['filters'], 'alert_frequency' => $data['alert_frequency'] ?? 'daily', 'status' => 'active', 'checked_until' => now()]);

        return ApiResponse::success($search->only(['id', 'name', 'filters', 'alert_frequency', 'status']), status: 201);
    }

    public function updateSavedSearch(Request $request, int $search): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:active,paused'], 'alert_frequency' => ['sometimes', 'in:instant,daily'], 'name' => ['sometimes', 'string', 'max:120']]);
        $search = SavedSearch::query()->where('customer_user_id', $request->user()->id)->findOrFail($search);
        $search->fill($data)->save();

        return ApiResponse::success($search->only(['id', 'name', 'filters', 'alert_frequency', 'status']));
    }

    public function deleteSavedSearch(Request $request, int $search): JsonResponse
    {
        SavedSearch::query()->where('customer_user_id', $request->user()->id)->findOrFail($search)->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    public function follow(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:shop,marketer'], 'slug' => ['required', 'string', 'max:160']]);
        $id = $data['type'] === 'shop'
            ? Vendor::query()->where('slug', $data['slug'])->where('status', Vendor::STATUS_ACTIVE)->value('id')
            : MarketerProfile::query()->where('slug', $data['slug'])->value('id');
        abort_if($id === null, 404);
        $attributes = ['customer_user_id' => $request->user()->id, 'followable_type' => $data['type'], 'followable_id' => $id];

        if ($request->isMethod('get')) {
            return ApiResponse::success([
                'following' => Follow::query()->where($attributes)->exists(),
                'followers' => Follow::query()->where(['followable_type' => $data['type'], 'followable_id' => $id])->count(),
            ]);
        }

        if ($request->isMethod('delete')) {
            Follow::query()->where($attributes)->delete();
        } else {
            Follow::query()->firstOrCreate($attributes);
        }

        return ApiResponse::success([
            'following' => ! $request->isMethod('delete'),
            'followers' => Follow::query()->where(['followable_type' => $data['type'], 'followable_id' => $id])->count(),
        ]);
    }

    private function ownThread(Request $request, int $id): ClassifiedThread
    {
        $userId = $request->user()->id;

        return ClassifiedThread::query()->where(fn ($query) => $query->where('customer_user_id', $userId)->orWhere('seller_user_id', $userId))->with('ad')->findOrFail($id);
    }
}
