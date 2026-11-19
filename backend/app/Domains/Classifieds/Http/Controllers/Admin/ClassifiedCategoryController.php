<?php

namespace App\Domains\Classifieds\Http\Controllers\Admin;

use App\Domains\Classifieds\Models\ClassifiedCategory;
use App\Domains\Countries\Models\CountryAuditLog;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Classified categories (RT-007): mark a classified category as
 * needing a signed contract before an ad can be posted in it (the flag
 * also covers its subcategories, {@see ClassifiedCategory::needsContract()}).
 */
class ClassifiedCategoryController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(): View
    {
        return view('panels.admin.classifieds.categories', [
            'categories' => ClassifiedCategory::query()->with('parent')->orderBy('section')->orderBy('parent_id')->orderBy('sort_order')->orderBy('name_en')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function contract(Request $request, int $category): RedirectResponse
    {
        $required = $request->validate(['requires_contract' => ['required', 'boolean']])['requires_contract'];
        $model = ClassifiedCategory::query()->findOrFail($category);
        $model->forceFill(['requires_contract' => (bool) $required])->save();

        CountryAuditLog::query()->create([
            'actor_user_id' => $request->user()->id, 'action' => 'classified_category.requires_contract',
            'subject_type' => 'classified_category', 'subject_id' => $model->id,
            'properties' => ['requires_contract' => (bool) $required], 'occurred_at' => now(),
        ]);

        return back()->with('status', (bool) $required
            ? __(':name now needs a signed contract before posting.', ['name' => $model->name_en])
            : __(':name no longer needs a contract.', ['name' => $model->name_en]));
    }

    /**
     * Photo limits per category: `max_photos` (empty = platform default)
     * and `photo_max_kb` (empty = inherit from the parent, then the default).
     */
    public function limits(Request $request, int $category): RedirectResponse
    {
        $data = $request->validate([
            'max_photos' => ['nullable', 'integer', 'between:1,50'],
            'photo_max_kb' => ['nullable', 'integer', 'between:100,20480'],
        ]);
        $model = ClassifiedCategory::query()->findOrFail($category);
        $model->forceFill(['max_photos' => $data['max_photos'] ?? 0, 'photo_max_kb' => $data['photo_max_kb'] ?? null])->save();

        CountryAuditLog::query()->create([
            'actor_user_id' => $request->user()->id, 'action' => 'classified_category.photo_limits',
            'subject_type' => 'classified_category', 'subject_id' => $model->id,
            'properties' => ['max_photos' => $data['max_photos'] ?? null, 'photo_max_kb' => $data['photo_max_kb'] ?? null], 'occurred_at' => now(),
        ]);

        return back()->with('status', __('Photo limits saved for :name.', ['name' => $model->name_en]));
    }
}
