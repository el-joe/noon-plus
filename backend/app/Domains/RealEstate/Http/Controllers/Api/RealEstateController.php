<?php

namespace App\Domains\RealEstate\Http\Controllers\Api;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Http\ClassifiedPresenter;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\Follow;
use App\Domains\Classifieds\Support\ClassifiedRequests;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\RealEstate\Models\BrokerDetail;
use App\Domains\RealEstate\Models\PropertyViewing;
use App\Domains\RealEstate\Models\SpecialRequest;
use App\Domains\RealEstate\Support\SpecialRequests;
use App\Domains\RealEstate\Support\Viewings;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Validation\ContactDetails;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Real-estate API (spec §5.7, MP v2 §14b broker profile): public broker
 * profile with licence and live properties, viewing requests on property
 * ads, and the customer's special requests.
 */
class RealEstateController extends Controller
{
    public function broker(string $slug): JsonResponse
    {
        $broker = MarketerProfile::query()->where('slug', $slug)->where('global_status', MarketerStatus::Active)->firstOrFail();
        abort_unless(in_array('broker', $broker->job_types ?? [], true), 404);
        $details = BrokerDetail::query()->where('marketer_profile_id', $broker->id)->first();
        $ads = ClassifiedAd::query()->where('marketer_profile_id', $broker->id)->where('status', ClassifiedAdStatus::Active)->with(['media', 'category', 'vendor', 'property'])->latest('bumped_at')->limit(48)->get();

        return ApiResponse::success([
            'slug' => $broker->slug,
            'name' => $broker->display_name,
            'bio' => $broker->bio,
            'verified' => (bool) $broker->is_verified,
            'licence' => $details === null ? null : ['number' => $details->licence_number, 'authority' => $details->licence_authority, 'expires_on' => $details->licence_expires_on?->toDateString()],
            'office' => $details?->office_name,
            'areas' => $details?->areas ?? [],
            'languages' => $details?->languages ?? [],
            'followers' => Follow::query()->where(['followable_type' => 'marketer', 'followable_id' => $broker->id])->count(),
            'properties' => $ads->map(fn (ClassifiedAd $ad): array => ClassifiedPresenter::card($ad))->all(),
        ]);
    }

    /**
     * D8 viewing / reserve request on any live classified ad whose vertical
     * takes that type (G-W22). `type` defaults to `viewing`.
     */
    public function requestViewing(Request $request, string $number, Viewings $viewings): JsonResponse
    {
        $data = $request->validate(ContactDetails::rules($request) + [
            'type' => ['nullable', Rule::in(ClassifiedRequests::TYPES)],
            'preferred_at' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $ad = ClassifiedAd::query()->where('number', $number)->with(['property', 'category'])->firstOrFail();
        $viewing = $viewings->request($ad, $request->user('sanctum')?->id, ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']], CarbonImmutable::parse($data['preferred_at']), $data['message'] ?? null, $data['type'] ?? 'viewing');

        return ApiResponse::success(['id' => $viewing->id, 'type' => $viewing->type, 'status' => $viewing->status->value], status: 201);
    }

    /**
     * The signed-in customer's own viewing / reserve requests, newest first (G-W22).
     */
    public function myViewings(Request $request): JsonResponse
    {
        $viewings = PropertyViewing::query()->where('customer_user_id', $request->user()->id)
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', (string) $type))
            ->with(['ad.media', 'ad.category', 'ad.vendor'])->latest('id')->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success(collect($viewings->items())->map(fn (PropertyViewing $viewing): array => [
            'id' => $viewing->id,
            'type' => $viewing->type,
            'status' => $viewing->status->value,
            'preferred_at' => $viewing->preferred_at->toIso8601String(),
            'scheduled_at' => $viewing->scheduled_at?->toIso8601String(),
            'note' => $viewing->note,
            'created_at' => $viewing->created_at?->toIso8601String(),
            'ad' => $viewing->ad === null ? null : array_intersect_key(ClassifiedPresenter::card($viewing->ad), array_flip(['number', 'title', 'price', 'image', 'city', 'area', 'status', 'category'])),
        ])->all(), ['total' => $viewings->total(), 'page' => $viewings->currentPage(), 'last_page' => $viewings->lastPage(), 'per_page' => $viewings->perPage()]);
    }

    public function specialRequests(Request $request): JsonResponse
    {
        return ApiResponse::success(SpecialRequest::query()->where('customer_user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (SpecialRequest $special): array => $special->only(['number', 'purpose', 'property_type', 'city', 'bedrooms', 'details']) + ['status' => $special->status->value, 'claimed' => $special->claimed_by_marketer_profile_id !== null])->all());
    }

    public function storeSpecialRequest(Request $request, SpecialRequests $requests): JsonResponse
    {
        $data = $request->validate([
            'classified_category_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'in:rent,sale,off_plan'],
            'property_type' => ['nullable', 'string', 'max:24'],
            'city' => ['nullable', 'string', 'max:80'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $special = $requests->create($request->user()->id, $data);

        return ApiResponse::success(['number' => $special->number, 'status' => $special->status->value], status: 201);
    }

    public function cancelSpecialRequest(Request $request, string $number, SpecialRequests $requests): JsonResponse
    {
        $special = SpecialRequest::query()->where('number', $number)->where('customer_user_id', $request->user()->id)->firstOrFail();

        return ApiResponse::success(['status' => $requests->cancel($special, $request->user()->id)->status->value]);
    }
}
