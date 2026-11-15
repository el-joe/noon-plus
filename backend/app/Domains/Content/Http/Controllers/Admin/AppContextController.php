<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\AppContext;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\AppContexts;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Content → App contexts (ADR 16): the customer app's super-app
 * switcher of this country — order, on/off, header theme, home layout and
 * bottom tab bar per context.
 */
class AppContextController extends Controller
{
    public function __construct(private CountryContext $countryContext, private AppContexts $contexts) {}

    public function index(): View
    {
        $this->contexts->ensureDefaults();

        return view('panels.admin.content.app-contexts', [
            'contexts' => AppContext::query()->orderBy('sort_order')->get(),
            'homePages' => Page::query()->where('template', PageTemplate::AppContext)->orderBy('slug')->get()->mapWithKeys(fn (Page $page): array => [$page->id => $page->title.' (/'.$page->slug.' · '.strtoupper($page->locale).')']),
            'targets' => AppContexts::TAB_TARGETS,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function update(Request $request, int $context): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:60'],
            'name_ar' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['required', 'integer', 'between:0,99'],
            'is_active' => ['required', 'boolean'],
            'theme' => ['required', 'array'],
            'theme.header_bg' => ['required', 'string'],
            'theme.header_fg' => ['required', 'string'],
            'theme.accent' => ['required', 'string'],
            'home_page_id' => ['nullable', 'integer'],
            'tabs' => ['required', 'array'],
            'tabs.*.label_en' => ['nullable', 'string', 'max:30'],
            'tabs.*.label_ar' => ['nullable', 'string', 'max:30'],
            'tabs.*.icon' => ['nullable', 'string', 'max:40'],
            'tabs.*.target' => ['nullable', Rule::in(AppContexts::TAB_TARGETS)],
        ]);
        $data['tabs'] = collect($data['tabs'])->filter(fn (array $tab): bool => ! empty($tab['target']) && ! empty($tab['label_en']))
            ->map(fn (array $tab): array => ['key' => $tab['target'], 'label_en' => $tab['label_en'], 'label_ar' => $tab['label_ar'] ?? $tab['label_en'], 'icon' => $tab['icon'] ?? null, 'target' => $tab['target']])
            ->values()->all();
        $data['is_active'] = (bool) $data['is_active'];
        $this->contexts->update(AppContext::query()->findOrFail($context), $data);

        return back()->with('status', __('App context saved.'));
    }
}
