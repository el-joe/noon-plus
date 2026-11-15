<?php

namespace App\Domains\Warranty\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Warranty\Models\WarrantyPlan;
use App\Http\Controllers\Controller;
use Brick\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Catalog → Warranty plans (SRS §4.1.2, §7.7): extended warranty
 * plans per parent category in the selected country, sold at checkout.
 * Plans are switched off rather than deleted (purchases reference them).
 */
class WarrantyPlanController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.admin.settings.warranty-plans', [
            'plans' => WarrantyPlan::query()->with('category')->orderBy('category_id')->orderBy('sort_order')->get(),
            'categories' => Category::query()->whereNull('parent_id')->orderBy('sort_order')->orderBy('name_en')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        WarrantyPlan::query()->create($this->validated($request));

        return back()->with('status', __('Warranty plan added.'));
    }

    public function update(Request $request, int $plan): RedirectResponse
    {
        WarrantyPlan::query()->findOrFail($plan)->update($this->validated($request));

        return back()->with('status', __('Warranty plan updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $decimals = Currency::of($this->countryContext->current()->currency)->getDefaultFractionDigits();

        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'duration_months' => ['required', 'integer', 'between:1,120'],
            'price' => ['required', 'decimal:0,'.$decimals, 'gt:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        return ['sort_order' => $data['sort_order'] ?? 0, 'is_active' => $request->boolean('is_active')] + $data;
    }
}
