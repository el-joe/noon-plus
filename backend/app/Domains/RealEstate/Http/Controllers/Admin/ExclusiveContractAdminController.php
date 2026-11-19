<?php

namespace App\Domains\RealEstate\Http\Controllers\Admin;

use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Marketers\Enums\MarketerStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\RealEstate\Models\ExclusiveContract;
use App\Domains\RealEstate\Support\ExclusiveContracts;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Real estate → Exclusive contracts (QC-009, SRS §11.13, lifecycle
 * §8.4): grant a marketer exclusivity on a classified ad (by number) or a
 * whole classified category for a window entered in the country's local
 * time, with an optional private contract file; revoke with a reason.
 */
class ExclusiveContractAdminController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private ExclusiveContracts $contracts, private CountryContext $countryContext, private CountryClock $clock) {}

    public function index(): View
    {
        return view('panels.admin.real-estate.exclusive-contracts', [
            'contracts' => ExclusiveContract::query()->with('marketer')->latest('id')->paginate(25),
            'marketers' => MarketerProfile::query()->where('global_status', MarketerStatus::Active)->orderBy('display_name')->pluck('display_name', 'id'),
            'categories' => ClassifiedCategory::query()->where('is_active', true)->orderBy('sort_order')->get()->mapWithKeys(fn (ClassifiedCategory $category): array => [$category->id => $category->name_en]),
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'marketer_profile_id' => ['required', 'integer', Rule::exists(MarketerProfile::class, 'id')],
            'subject_type' => ['required', Rule::in([ExclusiveContract::SUBJECT_AD, ExclusiveContract::SUBJECT_CATEGORY])],
            'ad_number' => ['nullable', 'required_if:subject_type,ad', 'string', 'max:40'],
            'category_id' => ['nullable', 'required_if:subject_type,category', 'integer', Rule::exists(ClassifiedCategory::class, 'id')],
            'starts_at' => ['required', 'date_format:'.self::LOCAL_FORMAT],
            'ends_at' => ['required', 'date_format:'.self::LOCAL_FORMAT],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $subjectId = $data['subject_type'] === ExclusiveContract::SUBJECT_AD
            ? ClassifiedAd::query()->where('number', trim((string) $data['ad_number']))->value('id')
            : (int) $data['category_id'];

        if ($subjectId === null) {
            return back()->withInput()->withErrors(['ad_number' => __('No ad has this number in this country.')]);
        }

        $country = $this->countryContext->current();

        try {
            $this->contracts->grant(
                MarketerProfile::query()->findOrFail($data['marketer_profile_id']),
                $data['subject_type'],
                (int) $subjectId,
                $this->clock->localToUtc($country, $data['starts_at']),
                $this->clock->localToUtc($country, $data['ends_at']),
                $request->user()->id,
                $request->file('file'),
            );
        } catch (TransitionRefused $refused) {
            return back()->withInput()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Exclusive contract granted.'));
    }

    public function revoke(Request $request, int $contract): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        try {
            $this->contracts->revoke(ExclusiveContract::query()->findOrFail($contract), $request->user()->id, $reason);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', __('Exclusive contract revoked.'));
    }

    public function file(int $contract): StreamedResponse
    {
        $path = ExclusiveContract::query()->findOrFail($contract)->file_path;
        abort_if($path === null || ! Storage::disk(ExclusiveContracts::DISK)->exists($path), 404);

        return Storage::disk(ExclusiveContracts::DISK)->download($path);
    }
}
