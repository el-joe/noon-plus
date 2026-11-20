<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageLayoutTemplate;
use App\Domains\Content\Support\BlockField;
use App\Domains\Content\Support\BlockRegistry;
use App\Domains\Content\Support\BlockSchemas;
use App\Domains\Content\Support\BlockSettings;
use App\Domains\Content\Support\PagePreview;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Support\Panel;
use App\Domains\Panels\Support\PanelCountries;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use App\Shared\Support\UserNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Content → Pages & page builder (SRS §15): pages per locale with
 * search and filters, and the visual block editor over the 57-block
 * registry — an outline of zones and sections (drag to reorder, hide,
 * duplicate, delete, schedule), an add-section gallery, settings forms
 * rendered from {@see BlockSchemas} (validated server-side by
 * {@see BlockSettings}, no JSON), revisions with restore, publish now or on
 * a schedule (country time with the Oman label), live preview of the draft,
 * archive / delete, duplicate, saved layouts and copy to other countries or
 * languages.
 */
class PageBuilderController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const ZONE_LABELS = ['main' => 'Main area', 'header' => 'Header', 'top' => 'Above the listing', 'listing' => 'Listing', 'bottom' => 'Below the listing'];

    public function __construct(private CountryContext $countryContext, private Pages $pages, private CountryClock $clock, private PagePreview $preview) {}

    public function index(Request $request, UserNames $names, PanelCountries $panelCountries): View|RedirectResponse
    {
        $country = $this->countryContext->current();
        $panel = Panel::fromRequest($request);
        $countries = $panelCountries->for($request->user(), $panel);

        // Country filter: switch the panel's country (pages live per country) and keep the other filters.
        if ($request->filled('country') && $request->query('country') !== $country->code) {
            $target = $countries->firstWhere('code', (string) $request->query('country'));
            abort_if($target === null, 403);
            $request->session()->put("panel_country.{$panel->key}", $target->id);

            return redirect()->route('admin.pages', $request->except('country', 'page'));
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'template' => ['nullable', Rule::enum(PageTemplate::class)],
            'status' => ['nullable', Rule::enum(PageStatus::class)],
            'locale' => ['nullable', Rule::in($country->locales)],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $pages = Page::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('slug', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($filters['template'] ?? null, fn ($query, string $template) => $query->where('template', $template))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['locale'] ?? null, fn ($query, string $locale) => $query->where('locale', $locale))
            ->withCount('revisions')
            ->orderByDesc('updated_at')->orderBy('slug')->orderBy('locale')
            ->paginate(30)->withQueryString();
        $names->preload($pages->pluck('updated_by')->filter());

        return view('panels.admin.content.pages', [
            'pages' => $pages,
            'names' => $names,
            'counts' => Page::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'layouts' => PageLayoutTemplate::query()->orderBy('name')->get(),
            'filters' => $filters,
            'country' => $country,
            'countries' => $countries,
            'otherCountries' => Country::query()->active()->whereKeyNot($country->id)->get(),
            'clock' => $this->clock,
            'preview' => $this->preview,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template' => ['required', Rule::enum(PageTemplate::class)],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'locale' => ['required', Rule::in($this->countryContext->current()->locales)],
            'title' => ['required', 'string', 'max:255'],
            'layout_id' => ['nullable', 'integer'],
        ]);

        if (Page::query()->where('slug', $data['slug'])->where('locale', $data['locale'])->exists()) {
            throw ValidationException::withMessages(['slug' => __('A page with this address and language already exists.')]);
        }

        $layout = isset($data['layout_id']) ? PageLayoutTemplate::query()->findOrFail($data['layout_id']) : null;
        $page = $this->pages->create($data, $request->user()->id, $layout);

        return redirect()->route('admin.pages.show', $page->id)->with('status', __('Page created as a draft.'));
    }

    public function show(Request $request, int $page, AdminAccess $access, UserNames $names): View
    {
        $page = Page::query()->with(['currentRevision', 'publishedRevision'])->findOrFail($page);
        $country = $this->countryContext->current();
        $zones = $page->currentRevision?->zones ?? array_fill_keys($page->template->zones(), []);
        $revisions = $page->revisions()->reorder()->latest('number')->limit(31)->get();
        $names->preload($revisions->pluck('created_by')->filter());
        $summaries = [];

        foreach ($revisions->values() as $index => $revision) {
            $previous = $revisions->values()->get($index + 1);
            $summaries[$revision->id] = $previous === null ? null : $this->diffSummary($this->zoneDiff($previous->zones, $revision->zones));
        }

        $sinceLive = $page->publishedRevision === null ? null : $this->zoneDiff($page->publishedRevision->zones, $zones);
        $siblings = Page::query()->where('slug', $page->slug)->get(['id', 'locale'])->pluck('id', 'locale');
        $linked = $this->pages->linked($page);
        $previewPage = $page;

        return view('panels.admin.content.page', [
            'page' => $page,
            'editor' => [
                ...$this->editorState($page, $zones, $country, $access->isSuperAdmin($request->user())),
                'previewUrls' => collect($country->locales)->mapWithKeys(fn (string $locale): array => [$locale => $this->preview->url($previewPage, $country, $locale)])->all(),
                'previewUrlsHidden' => collect($country->locales)->mapWithKeys(fn (string $locale): array => [$locale => $this->preview->url($previewPage, $country, $locale, true)])->all(),
                'storefront' => (string) preg_replace('#\\?preview=.*$#', '', $this->preview->url(Page::query()->make(['template' => PageTemplate::Home, 'slug' => 'home']), $country, $page->locale)),
                'savedAt' => $page->currentRevision ? $this->clock->inCountry($country, $page->currentRevision->created_at)->format('H:i') : null,
                'savedBy' => $page->currentRevision?->created_by ? $names->name($page->currentRevision->created_by) : null,
                'sinceLive' => $sinceLive,
                'pages' => $linked->mapWithKeys(fn (Page $other): array => [$other->locale => ['id' => $other->id, 'title' => $other->title, 'seo_title' => $other->seo_title, 'seo_description' => $other->seo_description, 'status' => $other->status->value]])->all(),
                'gaps' => $this->pages->translationGaps($page),
            ],
            'linked' => $linked,
            'revisions' => $revisions->take(30),
            'summaries' => $summaries,
            'sinceLive' => $sinceLive,
            'siblings' => $siblings,
            'names' => $names,
            'history' => StateTransition::query()->where('subject_type', $page->transitionSubject())->where('subject_id', $page->id)->orderBy('id')->get(),
            'problems' => $this->pages->publishProblems($page),
            'countries' => Country::query()->active()->whereKeyNot($country->id)->get(),
            'country' => $country,
            'clock' => $this->clock,
        ]);
    }

    /**
     * Sections edited, added, removed and moved between two revisions (matched by block id).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{edited: int, added: int, removed: int, moved: int, hidden: int, sections: array<string, string>}
     */
    private function zoneDiff(array $before, array $after): array
    {
        $index = fn (array $zones): array => collect($zones)->flatMap(fn ($blocks, $zone) => collect((array) $blocks)->values()->mapWithKeys(fn ($block, $position) => [(string) ($block['id'] ?? $zone.$position) => ['zone' => $zone, 'position' => $position, 'block' => $block]]))->all();
        $old = $index($before);
        $new = $index($after);
        $diff = ['edited' => 0, 'added' => 0, 'removed' => 0, 'moved' => 0, 'hidden' => 0, 'sections' => []];

        foreach ($new as $id => $entry) {
            if (! empty($entry['block']['hidden'])) {
                $diff['hidden']++;
            }

            if (! isset($old[$id])) {
                $diff['added']++;
                $diff['sections'][$id] = 'added';
            } elseif (json_encode($old[$id]['block']) !== json_encode($entry['block'])) {
                $diff['edited']++;
                $diff['sections'][$id] = 'changed';
            } elseif ($old[$id]['zone'] !== $entry['zone'] || $old[$id]['position'] !== $entry['position']) {
                $diff['moved']++;
                $diff['sections'][$id] = 'moved';
            }
        }

        $diff['removed'] = count(array_diff_key($old, $new));

        return $diff;
    }

    /**
     * @param  array{edited: int, added: int, removed: int, moved: int}  $diff
     */
    private function diffSummary(array $diff): string
    {
        $parts = array_filter([
            $diff['edited'] ? trans_choice(':count section edited|:count sections edited', $diff['edited']) : null,
            $diff['added'] ? trans_choice(':count added|:count added', $diff['added']) : null,
            $diff['removed'] ? trans_choice(':count removed|:count removed', $diff['removed']) : null,
            $diff['moved'] ? trans_choice(':count moved|:count moved', $diff['moved']) : null,
        ]);

        return $parts === [] ? __('No section changes') : implode(' · ', $parts);
    }

    /**
     * Save the editor as a new revision. The editor sends JSON zones
     * (`zones.{zone}[] = {id, type, hidden, settings}`), validated per field
     * from the block schemas; the older form fields (`blocks` rows with
     * settings JSON and an `order`, `add`) are still accepted.
     */
    public function save(Request $request, int $page): JsonResponse|RedirectResponse
    {
        $page = Page::query()->findOrFail($page);
        $data = $request->validate([
            'title' => ['required_without:pages', 'nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'pages' => ['nullable', 'array'],
            'pages.*.title' => ['required', 'string', 'max:255'],
            'pages.*.seo_title' => ['nullable', 'string', 'max:255'],
            'pages.*.seo_description' => ['nullable', 'string', 'max:500'],
            'zones' => ['nullable', 'array'],
            'blocks' => ['nullable', 'array'],
            'add' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->has('zones')) {
            // Older form posts: this page only.
            $zones = $this->zonesFromLegacyForm($page, $data);
            $page->forceFill(['title' => $data['title'], 'seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null])->save();

            return back()->with('status', __('Saved as revision :number.', ['number' => $this->pages->saveDraft($page, $zones, $request->user()->id, $data['note'] ?? null)->number]));
        }

        // The bilingual layout is shared by every language page of this address.
        $zones = $this->zonesFromEditor($page, (array) $data['zones']);
        $revision = null;

        foreach ($this->pages->linked($page) as $linked) {
            $fields = $data['pages'][$linked->locale] ?? ($linked->is($page) && isset($data['title']) ? $data : null);

            if ($fields !== null) {
                $linked->forceFill(['title' => $fields['title'], 'seo_title' => $fields['seo_title'] ?? null, 'seo_description' => $fields['seo_description'] ?? null])->save();
            }

            $saved = $this->pages->saveDraft($linked, $zones, $request->user()->id, $data['note'] ?? null);
            $revision = $linked->is($page) ? $saved : $revision;
        }

        $message = __('Saved as revision :number.', ['number' => $revision->number]);

        if ($request->expectsJson()) {
            $page->refresh();

            return response()->json([
                'message' => $message,
                'revision' => ['number' => $revision->number, 'at' => $this->clock->inAdminTime($revision->created_at)->format('j M H:i'), 'note' => $revision->note],
                'zones' => $this->editorZones($revision->zones, $page->locale, $this->countryContext->current()),
                'problems' => $this->pages->publishProblems($page),
                'savedAt' => $this->clock->inCountry($this->countryContext->current(), $revision->created_at)->format('H:i'),
                'sinceLive' => $page->publishedRevision === null ? null : $this->zoneDiff($page->publishedRevision->zones, $revision->zones),
            ]);
        }

        return back()->with('status', $message);
    }

    public function act(Request $request, int $page, string $action): RedirectResponse
    {
        $page = Page::query()->findOrFail($page);
        $userId = $request->user()->id;
        $country = $this->countryContext->current();

        if ($action === 'delete') {
            $this->pages->delete($page);

            return redirect()->route('admin.pages')->with('status', __('Page deleted.'));
        }

        if ($action === 'duplicate') {
            $data = $request->validate([
                'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
                'locale' => ['required', Rule::in($country->locales)],
                'title' => ['required', 'string', 'max:255'],
            ]);
            $copy = $this->pages->duplicate($page, $data['slug'], $data['locale'], $data['title'], $userId);

            return redirect()->route('admin.pages.show', $copy->id)->with('status', __('Duplicated as a new draft.'));
        }

        $message = match ($action) {
            'publish' => $this->publishLinked($request, $page, fn (Page $linked) => $linked->status === PageStatus::Archived ? null : $this->pages->publish($linked, ActorType::Admin, $userId)) ? __('Published.') : '',
            'schedule' => $this->publishLinked($request, $page, fn (Page $linked) => $linked->status === PageStatus::Draft ? $this->pages->schedule($linked, $this->clock->localToUtc($country, $request->validate(['publish_at' => ['required', 'date_format:'.self::LOCAL_FORMAT]])['publish_at']), $userId) : null) ? __('Scheduled.') : '',
            'draft' => $this->pages->linked($page)->each(fn (Page $linked) => $linked->status === PageStatus::Draft ? null : $this->pages->toDraft($linked, $userId)) ? __('Back to draft.') : '',
            'archive' => $this->pages->linked($page)->each(fn (Page $linked) => in_array($linked->status, [PageStatus::Draft, PageStatus::Published], true) ? $this->pages->archive($linked, $userId) : null) ? __('Archived.') : '',
            'restore' => __('Restored as revision :number.', ['number' => $this->pages->restore($page, (int) $request->validate(['revision' => ['required', 'integer']])['revision'], $userId)->number]),
            'copy' => __('Copied to :count countries as drafts.', ['count' => $this->copyLinkedToCountries($request, $page, $userId)]),
            'locales' => __('Copied to :count languages as drafts.', ['count' => $this->pages->copyToLocales($page, $request->validate(['locales' => ['required', 'array', 'min:1'], 'locales.*' => ['string', Rule::in($country->locales)]])['locales'], $userId)]),
            'layout' => __('Saved as layout ":name".', ['name' => $this->pages->saveAsLayout($page, $request->validate(['name' => ['required', 'string', 'max:120']])['name'], $userId)->name]),
        };

        return back()->with('status', $message);
    }

    /**
     * Publish (or schedule) every language page of the address. Content
     * missing in one language blocks it; super admins may publish anyway
     * (`force`).
     */
    private function publishLinked(Request $request, Page $page, callable $publish): bool
    {
        $gaps = $this->pages->translationGaps($page);

        if ($gaps !== [] && ! ($request->boolean('force') && app(AdminAccess::class)->isSuperAdmin($request->user()))) {
            throw new TransitionRefused(__('Add the missing translations first: :list', ['list' => implode('; ', array_slice($gaps, 0, 8)).(count($gaps) > 8 ? ' …' : '')]));
        }

        $this->pages->linked($page)->each(fn (Page $linked) => $publish($linked));

        return true;
    }

    private function copyLinkedToCountries(Request $request, Page $page, int $userId): int
    {
        $countries = $request->validate(['countries' => ['required', 'array', 'min:1'], 'countries.*' => ['string', 'size:3']])['countries'];
        $copied = 0;

        foreach ($this->pages->linked($page) as $linked) {
            $copied = max($copied, $this->pages->copyToCountries($linked, $countries, $userId));
        }

        return $copied;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function zonesFromEditor(Page $page, array $input): array
    {
        $converter = new BlockSettings($page->locale, $this->countryContext->current());
        $zones = [];

        foreach ($page->template->zones() as $zone) {
            $zones[$zone] = [];

            foreach (array_values((array) ($input[$zone] ?? [])) as $index => $block) {
                if (! is_array($block)) {
                    continue;
                }

                $type = (string) ($block['type'] ?? '');
                $zones[$zone][] = [
                    'id' => isset($block['id']) && is_string($block['id']) && $block['id'] !== '' ? $block['id'] : null,
                    'type' => $type,
                    'hidden' => ! empty($block['hidden']),
                    'locales' => array_values(array_filter((array) ($block['locales'] ?? []), 'is_string')),
                    'settings' => $converter->fromEditor($type, is_array($block['settings'] ?? null) ? $block['settings'] : [], "zones.{$zone}.{$index}.settings"),
                ];
            }
        }

        if ($converter->errors() !== []) {
            throw ValidationException::withMessages($converter->errors());
        }

        return $zones;
    }

    /**
     * Older form posts: rows sorted by their order field, removed rows
     * dropped, settings decoded from JSON, new blocks appended.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function zonesFromLegacyForm(Page $page, array $data): array
    {
        $zones = [];
        $errors = [];

        foreach ($page->template->zones() as $zone) {
            $rows = collect($data['blocks'][$zone] ?? [])->reject(fn ($row): bool => ! empty($row['remove']))->sortBy(fn ($row): int => (int) ($row['order'] ?? 0))->values();
            $zones[$zone] = [];

            foreach ($rows as $index => $row) {
                $settings = json_decode((string) ($row['settings'] ?? '{}'), true);

                if (! is_array($settings)) {
                    $errors["blocks.{$zone}.{$index}"] = __('Settings of :type in :zone are not valid JSON.', ['type' => $row['type'] ?? '?', 'zone' => $zone]);

                    continue;
                }

                $zones[$zone][] = ['id' => $row['id'] ?? null, 'type' => (string) ($row['type'] ?? ''), 'settings' => $settings];
            }

            if (! empty($data['add'][$zone])) {
                $zones[$zone][] = ['type' => (string) $data['add'][$zone], 'settings' => []];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $zones;
    }

    /**
     * @param  array<string, mixed>  $zones
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function editorZones(array $zones, string $locale, Country $country): array
    {
        $converter = new BlockSettings($locale, $country);
        $out = [];

        foreach ($zones as $zone => $blocks) {
            $out[$zone] = array_map(fn (array $block): array => [
                'id' => (string) ($block['id'] ?? ''),
                'type' => (string) $block['type'],
                'hidden' => ! empty($block['hidden']),
                'locales' => array_values((array) ($block['locales'] ?? [])),
                'gaps' => $converter->gaps((string) $block['type'], (array) ($block['settings'] ?? [])),
                'settings' => (object) $converter->toEditor((string) $block['type'], (array) ($block['settings'] ?? [])),
                'raw' => (object) ($block['settings'] ?? []),
            ], array_values((array) $blocks));
        }

        return $out;
    }

    /**
     * Everything the page editor script needs (embedded as JSON).
     *
     * @param  array<string, mixed>  $zones
     * @return array<string, mixed>
     */
    private function editorState(Page $page, array $zones, Country $country, bool $superAdmin): array
    {
        $types = [];

        foreach (BlockRegistry::all() as $type => $definition) {
            $types[$type] = [
                'label' => __($definition['label']),
                'description' => __($definition['description']),
                'group' => $definition['group'],
                'kind' => $definition['kind'],
                'required' => $definition['required'],
                'thumbnail' => file_exists(public_path("images/page-blocks/{$type}.png")) ? asset("images/page-blocks/{$type}.png") : null,
                'fields' => array_map(fn (BlockField $field): array => $field->jsonSerialize(), BlockSchemas::for($type)),
            ];
        }

        return [
            'page' => ['id' => $page->id, 'title' => $page->title, 'seo_title' => $page->seo_title, 'seo_description' => $page->seo_description, 'locale' => $page->locale, 'template' => $page->template->value, 'slug' => $page->slug],
            'zones' => $this->editorZones($zones, $page->locale, $country),
            'zoneOrder' => $page->template->zones(),
            'zoneLabels' => collect($page->template->zones())->mapWithKeys(fn (string $zone): array => [$zone => __(self::ZONE_LABELS[$zone] ?? ucfirst($zone))])->all(),
            'allowed' => collect($page->template->zones())->mapWithKeys(fn (string $zone): array => [$zone => array_values(array_filter(array_keys(BlockRegistry::all()), fn (string $type): bool => BlockRegistry::allowedIn($type, $zone)))])->all(),
            'childTypes' => array_values(array_keys(array_filter(BlockRegistry::all(), fn (array $definition): bool => ! in_array($definition['kind'], ['header', 'listing', 'container'], true)))),
            'types' => $types,
            'groups' => collect(BlockRegistry::GROUPS)->map(fn (string $label): string => __($label))->all(),
            'fieldGroups' => collect(BlockSchemas::groups())->map(fn (string $label): string => __($label))->all(),
            'country' => ['code' => $country->code, 'timezone' => $country->timezone, 'locales' => $country->locales],
            'superAdmin' => $superAdmin,
            'urls' => [
                'save' => route('admin.pages.save', $page->id),
                'picker' => route('admin.pages.picker', ['kind' => '__kind__']),
                'upload' => route('admin.pages.upload'),
            ],
        ];
    }
}
