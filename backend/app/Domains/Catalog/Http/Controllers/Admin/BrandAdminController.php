<?php

namespace App\Domains\Catalog\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use App\Shared\Http\LikeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Catalog → Brands (QC-003): the country's brands with logo and
 * Arabic name; audited.
 */
class BrandAdminController extends Controller
{
    public function __construct(private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        return view('panels.admin.catalog.brands', [
            'brands' => Brand::query()
                ->addSelect(['products_count' => Product::query()->selectRaw('count(*)')->whereColumn('brand_id', 'brands.id')])
                ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner->where('name', 'like', LikeSearch::contains($search))->orWhere('slug', 'like', LikeSearch::contains($search))))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
                ->orderBy('name')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Brand::query()->create($this->validated($request, null));

        return back()->with('status', __('Brand added.'));
    }

    public function edit(int $brand): View
    {
        return view('panels.admin.catalog.brand-form', ['brand' => Brand::query()->findOrFail($brand), 'country' => $this->countryContext->current()]);
    }

    public function update(Request $request, int $brand): RedirectResponse
    {
        $brand = Brand::query()->findOrFail($brand);
        $brand->update($this->validated($request, $brand));

        return redirect()->route('admin.catalog.brands')->with('status', __('Brand saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Brand $brand): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique(Brand::class, 'slug')->ignore($brand?->id)],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:1024'],
            'is_active' => ['boolean'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('brands/'.$this->countryContext->current()->code, 'public');

            if ($brand?->logo_path !== null) {
                Storage::disk('public')->delete($brand->logo_path);
            }
        }

        unset($data['logo']);
        $data['is_active'] ??= true;

        return $data;
    }
}
