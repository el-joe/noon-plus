<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageLayoutTemplate;
use App\Domains\Content\Support\BlockRegistry;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Content → Pages & page builder (SRS §15): pages per locale, the
 * block editor over the 51-block registry (order, remove, add, settings as
 * JSON), revisions with restore, publish now or on a schedule (country time
 * with the Oman label), archive, saved layouts and copy to other countries.
 */
class PageBuilderController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private CountryContext $countryContext, private Pages $pages, private CountryClock $clock) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['template' => ['nullable', Rule::enum(PageTemplate::class)], 'status' => ['nullable', Rule::enum(PageStatus::class)]]);

        return view('panels.admin.content.pages', [
            'pages' => Page::query()
                ->when($filters['template'] ?? null, fn ($query, string $template) => $query->where('template', $template))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->orderBy('slug')->orderBy('locale')->paginate(30)->withQueryString(),
            'layouts' => PageLayoutTemplate::query()->orderBy('name')->get(),
            'filters' => $filters,
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
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

    public function show(int $page): View
    {
        $page = Page::query()->with(['currentRevision', 'publishedRevision'])->findOrFail($page);

        return view('panels.admin.content.page', [
            'page' => $page,
            'zones' => $page->currentRevision?->zones ?? array_fill_keys($page->template->zones(), []),
            'revisions' => $page->revisions()->latest('number')->limit(20)->get(),
            'history' => StateTransition::query()->where('subject_type', $page->transitionSubject())->where('subject_id', $page->id)->orderBy('id')->get(),
            'problems' => $this->pages->publishProblems($page),
            'blockGroups' => BlockRegistry::grouped(),
            'countries' => Country::query()->active()->whereKeyNot($this->countryContext->current()->id)->orderBy('sort_order')->get(),
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    /**
     * Save the editor as a new revision: rows sorted by their order field,
     * removed rows dropped, settings decoded from JSON, new blocks appended.
     */
    public function save(Request $request, int $page): RedirectResponse
    {
        $page = Page::query()->findOrFail($page);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'blocks' => ['nullable', 'array'],
            'add' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
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

        $page->forceFill(['title' => $data['title'], 'seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null])->save();
        $revision = $this->pages->saveDraft($page, $zones, $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('Saved as revision :number.', ['number' => $revision->number]));
    }

    public function act(Request $request, int $page, string $action): RedirectResponse
    {
        $page = Page::query()->findOrFail($page);
        $userId = $request->user()->id;

        $message = match ($action) {
            'publish' => $this->pages->publish($page, ActorType::Admin, $userId) ? __('Published.') : '',
            'schedule' => $this->pages->schedule($page, $this->clock->localToUtc($this->countryContext->current(), $request->validate(['publish_at' => ['required', 'date_format:'.self::LOCAL_FORMAT]])['publish_at']), $userId) ? __('Scheduled.') : '',
            'draft' => $this->pages->toDraft($page, $userId) ? __('Back to draft.') : '',
            'archive' => $this->pages->archive($page, $userId) ? __('Archived.') : '',
            'restore' => __('Restored as revision :number.', ['number' => $this->pages->restore($page, (int) $request->validate(['revision' => ['required', 'integer']])['revision'], $userId)->number]),
            'copy' => __('Copied to :count countries as drafts.', ['count' => $this->pages->copyToCountries($page, $request->validate(['countries' => ['required', 'array', 'min:1'], 'countries.*' => ['string', 'size:3']])['countries'], $userId)]),
            'layout' => __('Saved as layout ":name".', ['name' => $this->pages->saveAsLayout($page, $request->validate(['name' => ['required', 'string', 'max:120']])['name'], $userId)->name]),
        };

        return back()->with('status', $message);
    }
}
