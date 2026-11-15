<?php

namespace App\Domains\Catalog\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryAttribute;
use App\Domains\Catalog\Models\SizeGuideTemplate;
use App\Domains\Catalog\Support\CategoryCopier;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Catalog → Categories (QC-003, FR-CAT-1): the country's category
 * tree, bilingual names, image, commission rate, return window, contract
 * and certification gates, default size guide, attributes and filters; and
 * copying categories to other countries (QA-011). Changes are audited
 * (activitylog, with the country).
 */
class CategoryAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request, PanelCountries $panelCountries): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $flat = filled($filters['q'] ?? null) || filled($filters['status'] ?? null);

        $categories = Category::query()
            ->withCount('products')
            ->when(! $flat, fn ($query) => $query->whereNull('parent_id')->with('children.children'))
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner
                ->where('name_en', 'like', LikeSearch::contains($search))
                ->orWhere('name_ar', 'like', LikeSearch::contains($search))
                ->orWhere('slug', 'like', LikeSearch::contains($search))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('panels.admin.catalog.categories', [
            'categories' => $categories,
            'flat' => $flat,
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'roots' => Category::query()->whereNull('parent_id')->orderBy('sort_order')->get(['id', 'name_en']),
            'copyTargets' => $this->copyTargets($request, $panelCountries),
        ]);
    }

    public function show(int $category): View
    {
        $category = Category::query()->with(['parent', 'children', 'attributeDefinitions', 'sizeGuide'])->withCount('products')->findOrFail($category);

        return view('panels.admin.catalog.category', [
            'category' => $category,
            'country' => $this->countryContext->current(),
            'canManage' => request()->user()->can('categories.manage'),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form(new Category(['is_active' => true, 'parent_id' => $request->integer('parent') ?: null]));
    }

    public function store(Request $request): RedirectResponse
    {
        $category = Category::query()->create($this->validated($request, null));

        return redirect()->route('admin.catalog.categories.show', $category->id)->with('status', __('Category created.'));
    }

    public function edit(int $category): View
    {
        return $this->form(Category::query()->findOrFail($category));
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $category = Category::query()->findOrFail($category);
        $category->update($this->validated($request, $category));

        return redirect()->route('admin.catalog.categories.show', $category->id)->with('status', __('Category saved.'));
    }

    public function storeAttribute(Request $request, int $category): RedirectResponse
    {
        $category = Category::query()->findOrFail($category);
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:48', Rule::unique(CategoryAttribute::class)->where('category_id', $category->id)],
            'label_en' => ['required', 'string', 'max:120'],
            'label_ar' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(CategoryAttribute::TYPES)],
            'options' => ['nullable', 'required_if:type,select', 'string', 'max:1000'],
            'is_required' => ['boolean'],
            'is_filterable' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $data['options'] = $data['type'] === 'select'
            ? array_values(array_filter(array_map('trim', explode(',', (string) $data['options'])), fn (string $option): bool => $option !== ''))
            : null;
        $category->attributeDefinitions()->create($data + ['sort_order' => 0]);

        return back()->with('status', __('Attribute added.'));
    }

    public function destroyAttribute(int $attribute): RedirectResponse
    {
        CategoryAttribute::query()->findOrFail($attribute)->delete();

        return back()->with('status', __('Attribute removed.'));
    }

    /**
     * QA-011: copy categories (with sub-categories, attributes and
     * measurement fields) to countries the admin manages categories in.
     */
    public function copy(Request $request, CategoryCopier $copier, PanelCountries $panelCountries): RedirectResponse
    {
        $allowed = $this->copyTargets($request, $panelCountries)->pluck('code')->all();
        $data = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', Rule::exists(Category::class, 'id')],
            'countries' => ['required', 'array', 'min:1'],
            'countries.*' => ['string', Rule::in($allowed)],
            'with_commission' => ['boolean'],
        ]);
        $result = $copier->copy($data['category_ids'], Country::query()->whereIn('code', $data['countries'])->get(), (bool) ($data['with_commission'] ?? false));

        activity('categories')->causedBy($request->user())->withProperties([
            'country' => $this->countryContext->current()->code,
            'category_ids' => $data['category_ids'],
            'result' => $result,
        ])->event('copied')->log('Categories copied to other countries');

        return back()->with('status', __('Categories copied: :summary.', [
            'summary' => collect($result)->map(fn (array $counts, string $code): string => strtoupper($code).' +'.$counts['created'].' / ~'.$counts['updated'])->implode(', '),
        ]));
    }

    /**
     * Countries other than the current one where the admin may manage categories.
     *
     * @return Collection<int, Country>
     */
    private function copyTargets(Request $request, PanelCountries $panelCountries): Collection
    {
        $current = $this->countryContext->current();
        $access = app(AdminAccess::class);

        return $panelCountries->for($request->user(), Panel::find('admin'))
            ->reject(fn (Country $country): bool => $country->is($current))
            ->filter(fn (Country $country): bool => $this->countryContext->runAs($country, fn (): bool => $access->allows($request->user(), AdminPermission::CategoriesManage)))
            ->values();
    }

    private function form(Category $category): View
    {
        return view('panels.admin.catalog.category-form', [
            'category' => $category,
            'country' => $this->countryContext->current(),
            'parents' => Category::query()->when($category->exists, fn ($query) => $query->whereKeyNot($category->id))->orderBy('name_en')->get(['id', 'name_en', 'parent_id']),
            'sizeGuides' => SizeGuideTemplate::query()->whereNull('vendor_id')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category): array
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id'), Rule::notIn($category === null ? [] : $this->descendantIds($category))],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique(Category::class, 'slug')->ignore($category?->id)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'commission_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'return_window_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'size_guide_template_id' => ['nullable', 'integer', Rule::exists(SizeGuideTemplate::class, 'id')],
            'requires_contract' => ['boolean'],
            'requires_certification' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('categories/'.$this->countryContext->current()->code, 'public');

            if ($category?->image_path !== null) {
                Storage::disk('public')->delete($category->image_path);
            }
        }

        unset($data['image']);
        $data['sort_order'] ??= 0;

        return $data;
    }

    /**
     * The category and every descendant (a category cannot move under itself).
     *
     * @return array<int, int>
     */
    private function descendantIds(Category $category): array
    {
        $ids = [$category->id];
        $frontier = [$category->id];

        for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $frontier = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
