<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageLayoutTemplate;
use App\Domains\Content\Models\PageRevision;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\StateTransition;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Page builder (SRS §15, page-builder-blocks.md): pages per country ×
 * locale, blocks from the {@see BlockRegistry} placed in the template's
 * zones, append-only revisions (restore = copy into a new revision),
 * publish now or on a schedule (PageSchedulerJob every 5 min), archive,
 * saved layout templates and copy to other countries (as drafts).
 * Publishing is refused while a block misses required settings.
 */
class Pages
{
    public function __construct(private StateMachine $machine, private CountryContext $countryContext) {}

    /**
     * @param  array{template: string, slug: string, locale: string, title: string, seo_title?: string|null, seo_description?: string|null}  $data
     */
    public function create(array $data, int $userId, ?PageLayoutTemplate $layout = null): Page
    {
        $template = PageTemplate::from($data['template']);

        if ($layout !== null && $layout->template !== $template) {
            throw new TransitionRefused(__('That layout is for another page template.'));
        }

        return DB::connection((new Page)->getConnectionName())->transaction(function () use ($data, $template, $userId, $layout): Page {
            $page = Page::query()->create([
                'template' => $template,
                'slug' => Str::slug($data['slug']) ?: 'home',
                'locale' => $data['locale'],
                'title' => $data['title'],
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'status' => PageStatus::Draft,
                'updated_by' => $userId,
            ]);
            $this->machine->created($page, ActorType::Admin, $userId);
            $this->saveDraft($page, $layout?->zones ?? array_fill_keys($template->zones(), []), $userId, $layout ? __('From layout :name', ['name' => $layout->name]) : null);

            return $page->refresh();
        });
    }

    /**
     * Save the blocks as a new revision (the page's current draft).
     *
     * @param  array<string, mixed>  $zones
     */
    public function saveDraft(Page $page, array $zones, int $userId, ?string $note = null): PageRevision
    {
        $zones = $this->normalize($page->template, $zones);

        return DB::connection($page->getConnectionName())->transaction(function () use ($page, $zones, $userId, $note): PageRevision {
            $number = (int) PageRevision::query()->where('page_id', $page->id)->lockForUpdate()->max('number') + 1;
            $revision = PageRevision::query()->create(['page_id' => $page->id, 'number' => $number, 'zones' => $zones, 'note' => $note, 'created_by' => $userId]);
            $page->forceFill(['current_revision_id' => $revision->id, 'updated_by' => $userId])->save();

            return $revision;
        });
    }

    /**
     * Copy an older revision into a new one (revisions stay append-only).
     */
    public function restore(Page $page, int $revisionNumber, int $userId): PageRevision
    {
        $old = PageRevision::query()->where('page_id', $page->id)->where('number', $revisionNumber)->firstOrFail();

        return $this->saveDraft($page, $old->zones, $userId, __('Restored revision :number', ['number' => $revisionNumber]));
    }

    public function publish(Page $page, ActorType $actorType, ?int $userId): Page
    {
        $this->guardPublishable($page);

        if ($page->status === PageStatus::Published) {
            $page->forceFill(['published_revision_id' => $page->current_revision_id, 'published_at' => now()])->save();
            StateTransition::query()->create([
                'subject_type' => $page->transitionSubject(), 'subject_id' => $page->id, 'from_status' => 'published', 'to_status' => 'published',
                'actor_type' => $actorType, 'actor_user_id' => $userId, 'reason' => __('Republished revision :id', ['id' => $page->current_revision_id]),
            ]);
        } else {
            $this->machine->move($page, PageStatus::Published, $actorType, $userId, null, [
                'published_revision_id' => $page->current_revision_id, 'published_at' => now(), 'publish_at' => null,
            ]);
        }

        $this->forget($page);

        return $page;
    }

    /**
     * Publish later (UTC moment; entered in country time with the Oman label).
     */
    public function schedule(Page $page, CarbonImmutable $at, int $userId): Page
    {
        if ($at->lessThanOrEqualTo(now())) {
            throw new TransitionRefused(__('Pick a time in the future.'));
        }

        $this->guardPublishable($page);

        return $this->machine->move($page, PageStatus::Scheduled, ActorType::Admin, $userId, null, ['publish_at' => $at]);
    }

    /**
     * Back to draft (unschedule or unpublish).
     */
    public function toDraft(Page $page, int $userId): Page
    {
        $this->machine->move($page, PageStatus::Draft, ActorType::Admin, $userId, null, ['publish_at' => null]);
        $this->forget($page);

        return $page;
    }

    public function archive(Page $page, int $userId): Page
    {
        $this->machine->move($page, PageStatus::Archived, ActorType::Admin, $userId);
        $this->forget($page);

        return $page;
    }

    /**
     * PageSchedulerJob: publish scheduled pages whose time has come.
     */
    public function publishDue(): int
    {
        $published = 0;

        Page::query()->where('status', PageStatus::Scheduled)->where('publish_at', '<=', now())->each(function (Page $page) use (&$published): void {
            $this->publish($page, ActorType::System, null);
            $published++;
        });

        return $published;
    }

    /**
     * Copy the page's current draft to other countries as drafts (SRS §15).
     * An existing page with the same slug and locale gets a new revision.
     *
     * @param  array<int, string>  $countryCodes
     * @return int pages written
     */
    public function copyToCountries(Page $page, array $countryCodes, int $userId): int
    {
        $source = $this->countryContext->current();
        $zones = $page->currentRevision()->firstOrFail()->zones;
        $copied = 0;

        foreach (Country::query()->active()->whereIn('code', $countryCodes)->where('id', '!=', $source->id)->get() as $country) {
            $this->countryContext->runAs($country, function () use ($page, $zones, $userId, $source, &$copied): void {
                $target = Page::query()->where('slug', $page->slug)->where('locale', $page->locale)->first()
                    ?? $this->create(['template' => $page->template->value, 'slug' => $page->slug, 'locale' => $page->locale, 'title' => $page->title, 'seo_title' => $page->seo_title, 'seo_description' => $page->seo_description], $userId);

                if ($target->template !== $page->template) {
                    throw new TransitionRefused(__(':country already has this page with another template.', ['country' => $this->countryContext->current()->name_en]));
                }

                $this->saveDraft($target, $zones, $userId, __('Copied from :country', ['country' => $source->code]));
                $copied++;
            });
        }

        return $copied;
    }

    public function saveAsLayout(Page $page, string $name, int $userId): PageLayoutTemplate
    {
        return PageLayoutTemplate::query()->create([
            'name' => $name,
            'template' => $page->template,
            'zones' => $page->currentRevision()->firstOrFail()->zones,
            'created_by' => $userId,
        ]);
    }

    /**
     * Problems that block publishing ([] = ready).
     *
     * @return array<int, string>
     */
    public function publishProblems(Page $page): array
    {
        $revision = $page->currentRevision()->first();

        if ($revision === null) {
            return [__('The page has no content yet.')];
        }

        $problems = [];

        foreach ($revision->zones as $zone => $blocks) {
            foreach ($this->flatten($blocks) as $position => $block) {
                foreach (BlockRegistry::missing($block['type'], $block['settings']) as $missing) {
                    $problems[] = __(':zone #:position :type needs :setting', ['zone' => $zone, 'position' => $position + 1, 'type' => $block['type'], 'setting' => $missing]);
                }
            }
        }

        return $problems;
    }

    public function cacheKey(string $countryCode, string $locale, string $slug): string
    {
        return "page:{$countryCode}:{$locale}:{$slug}";
    }

    /**
     * Check and tidy the zones: known zones of the template, registered
     * block types allowed in that zone, settings as an object, an id per block.
     *
     * @param  array<string, mixed>  $zones
     * @return array<string, array<int, array{id: string, type: string, settings: array<string, mixed>}>>
     */
    public function normalize(PageTemplate $template, array $zones): array
    {
        $errors = [];
        $clean = array_fill_keys($template->zones(), []);

        foreach ($zones as $zone => $blocks) {
            if (! in_array($zone, $template->zones(), true)) {
                $errors["zones.{$zone}"] = __('The :template template has no ":zone" zone.', ['template' => $template->label(), 'zone' => $zone]);

                continue;
            }

            foreach (array_values((array) $blocks) as $index => $block) {
                $type = is_array($block) ? (string) ($block['type'] ?? '') : '';

                if (! BlockRegistry::has($type)) {
                    $errors["zones.{$zone}.{$index}"] = __('Unknown block type ":type".', ['type' => $type]);

                    continue;
                }

                if (! BlockRegistry::allowedIn($type, $zone)) {
                    $errors["zones.{$zone}.{$index}"] = __('A :type block cannot go in the :zone zone.', ['type' => $type, 'zone' => $zone]);

                    continue;
                }

                $settings = $block['settings'] ?? [];

                if (! is_array($settings)) {
                    $errors["zones.{$zone}.{$index}"] = __('Block settings must be an object.');

                    continue;
                }

                foreach ((array) ($settings['children'] ?? []) as $child) {
                    if (! is_array($child) || ! BlockRegistry::has((string) ($child['type'] ?? '')) || in_array(BlockRegistry::get($child['type'])['kind'], ['header', 'listing', 'container'], true)) {
                        $errors["zones.{$zone}.{$index}"] = __('A container holds only content blocks.');
                    }
                }

                $clean[$zone][] = ['id' => (string) ($block['id'] ?? Str::uuid()), 'type' => $type, 'settings' => $settings];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Blocks of a zone plus the children of containers.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array{type: string, settings: array<string, mixed>}>
     */
    private function flatten(array $blocks): array
    {
        $flat = [];

        foreach ($blocks as $block) {
            $flat[] = ['type' => $block['type'], 'settings' => (array) ($block['settings'] ?? [])];

            foreach ((array) ($block['settings']['children'] ?? []) as $child) {
                $flat[] = ['type' => $child['type'], 'settings' => (array) ($child['settings'] ?? [])];
            }
        }

        return $flat;
    }

    private function guardPublishable(Page $page): void
    {
        $problems = $this->publishProblems($page);

        if ($problems !== []) {
            throw new TransitionRefused(__('Fix before publishing: :problems', ['problems' => implode('; ', $problems)]));
        }
    }

    private function forget(Page $page): void
    {
        Cache::forget($this->cacheKey($this->countryContext->current()->code, $page->locale, $page->slug));
    }
}
