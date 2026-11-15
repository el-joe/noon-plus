<?php

namespace App\Domains\Countries\Http\Controllers\Admin;

use App\Domains\Countries\Models\CountryCalendar;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Platform settings → Holidays (country-reference §6): the public
 * holidays of the selected country that delivery ETAs, SLAs and payouts skip.
 * Dates are country-local calendar days. Changes are audited (activitylog).
 */
class HolidayController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = $request->integer('year') ?: (int) now()->year;
        $country = $this->countryContext->current();

        return view('panels.admin.settings.holidays', [
            'country' => $country,
            'year' => $year,
            'holidays' => $country->holidays()->where(fn ($query) => $query->whereYear('starts_on', $year)->orWhereYear('ends_on', $year))->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->countryContext->current()->holidays()->create($this->validated($request) + ['updated_by' => $request->user()->id]);

        return back()->with('status', __('Holiday added.'));
    }

    public function update(Request $request, int $holiday): RedirectResponse
    {
        $this->ownHoliday($holiday)->update($this->validated($request) + ['updated_by' => $request->user()->id]);

        return back()->with('status', __('Holiday updated.'));
    }

    public function destroy(int $holiday): RedirectResponse
    {
        $this->ownHoliday($holiday)->delete();

        return back()->with('status', __('Holiday removed.'));
    }

    /**
     * A holiday of the selected country only (404 otherwise).
     */
    private function ownHoliday(int $id): CountryCalendar
    {
        return CountryCalendar::query()->where('country_id', $this->countryContext->current()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_confirmed' => ['boolean'],
        ]);

        return [
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? $data['starts_on'],
            'is_confirmed' => $request->boolean('is_confirmed'),
        ];
    }
}
