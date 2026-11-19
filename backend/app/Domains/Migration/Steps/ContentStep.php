<?php

namespace App\Domains\Migration\Steps;

use App\Domains\Migration\Support\BlockTypeMap;
use App\Domains\Migration\Support\MigrationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Content (MG8, plan §6 "Content"). Content with a country goes to that
 * country; content without one is global and goes to every country:
 * - page-builder pages (one per locale, slug kept — "-legacy" when the
 *   country already has that page) with their blocks converted through
 *   BlockTypeMap into one revision; a page with a block to review is a draft;
 *   legacy page/block revisions are archived as history;
 * - blog categories and posts; help centre categories/articles (portal
 *   help centre → hub help_center, seller audience) and ad-support
 *   collections/articles (→ hub knowledge_hub);
 * - static pages (central): an existing placeholder page of the same slug
 *   takes the legacy content, a written one is kept.
 */
class ContentStep extends Step
{
    public function name(): string
    {
        return 'content';
    }

    protected function order(): array
    {
        return ['pages', 'blog_categories', 'blog_posts', 'help_center_categories', 'help_center_articles', 'ad_support_collections', 'ad_support_articles', 'static_pages'];
    }

    protected function migratePages(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $codes = $row['country_id'] === null ? $this->context->targetCountries() : array_filter([$this->singleCountry($table, $row)]);

            if ($row['deleted_at'] !== null) {
                $this->archiveRow($table, $row, 'R1', ['codes' => [], 'reason' => 'central'], 'deleted_page');

                continue;
            }

            $blocks = $this->source->connection()->table('page_blocks')->where('page_id', $id)->whereNull('deleted_at')
                ->orderBy('position')->orderBy('id')->get()->map(fn (object $block): array => (array) $block)->all();

            foreach ($codes as $code) {
                foreach (['en', 'ar'] as $locale) {
                    $this->page($table, $row, $blocks, $code, $locale);
                }
            }
        }
    }

    protected function migrateBlogCategories(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            foreach ($this->context->targetCountries() as $code) {
                $this->writer->upsert($table, (string) $row['id'], $code, 'blog_categories', [
                    'parent_id' => $row['parent_id'] ? $this->ids->find($table, (string) $row['parent_id'], $code, 'blog_categories') : null,
                    'slug' => mb_substr((string) $row['slug'], 0, 80),
                    'name_en' => mb_substr($this->bilingual($row, 'name', 'en'), 0, 120),
                    'name_ar' => mb_substr($this->bilingual($row, 'name', 'ar'), 0, 120),
                    'sort_order' => (int) $row['sort_order'],
                    ...$this->timestamps($row),
                ], ['slug' => mb_substr((string) $row['slug'], 0, 80)]);
            }
        }
    }

    protected function migrateBlogPosts(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            foreach ($this->countriesOf($table, $row) as $code) {
                $this->writer->upsert($table, $id, $code, 'blog_posts', [
                    'category_id' => $row['blog_category_id'] ? $this->ids->find('blog_categories', (string) $row['blog_category_id'], $code, 'blog_categories') : null,
                    'slug' => $this->writer->unique($code, 'blog_posts', 'slug', (string) $row['slug'], $this->ids->find($table, $id, $code, 'blog_posts'), [], 160),
                    'title_en' => $this->bilingual($row, 'title', 'en'),
                    'title_ar' => $this->bilingual($row, 'title', 'ar'),
                    'excerpt_en' => $row['excerpt_en'],
                    'excerpt_ar' => $row['excerpt_ar'],
                    'body_en' => (string) ($row['body_en'] ?? $row['body_ar'] ?? ''),
                    'body_ar' => (string) ($row['body_ar'] ?? $row['body_en'] ?? ''),
                    'cover_path' => $row['featured_image_path'] ? 'legacy/'.ltrim((string) $row['featured_image_path'], '/') : null,
                    'tags' => json_decode((string) ($row['tags'] ?? 'null'), true),
                    'is_featured' => (bool) $row['is_featured'],
                    'status' => match ($row['status']) {
                        'published' => 'published',
                        'scheduled' => 'scheduled',
                        'archived' => 'archived',
                        default => 'draft',
                    },
                    'publish_at' => $row['scheduled_for'],
                    'published_at' => $row['published_at'],
                    'views' => (int) $row['views_count'],
                    'author_user_id' => $row['author_admin_id'] ? $this->ids->find('admins', (string) $row['author_admin_id'], MigrationContext::CENTRAL, 'users') : null,
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    protected function migrateHelpCenterCategories(string $table): void
    {
        $this->collections($table, 'help_center', 'seller');
    }

    protected function migrateAdSupportCollections(string $table): void
    {
        $this->collections($table, 'knowledge_hub', 'seller');
    }

    protected function migrateHelpCenterArticles(string $table): void
    {
        $this->articles($table, 'help_center', 'help_center_categories', 'help_center_category_id');
    }

    protected function migrateAdSupportArticles(string $table): void
    {
        $this->articles($table, 'knowledge_hub', 'ad_support_collections', 'ad_support_collection_id');
    }

    protected function migrateStaticPages(string $table): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];
            $countryId = $row['country_id'] === null ? null : DB::table('countries')->where('code', $this->countries->code((string) $row['country_id']))->value('id');

            if ($row['country_id'] !== null && $countryId === null) {
                $this->quarantine->put($table, $id, 'country_unresolved', 'static page country not launched', null, $row);

                continue;
            }

            $slug = mb_substr((string) $row['slug'], 0, 64);
            $values = [
                'country_id' => $countryId,
                'slug' => $slug,
                'audience' => in_array($row['audience'], ['customer', 'seller', 'both'], true) ? $row['audience'] : 'both',
                'title_en' => $this->bilingual($row, 'title', 'en'),
                'title_ar' => $this->bilingual($row, 'title', 'ar'),
                'body_en' => (string) ($row['content_en'] ?? $row['content_ar'] ?? ''),
                'body_ar' => (string) ($row['content_ar'] ?? $row['content_en'] ?? ''),
                'seo_title_en' => $row['seo_title_en'] === null ? null : mb_substr((string) $row['seo_title_en'], 0, 160),
                'seo_title_ar' => $row['seo_title_ar'] === null ? null : mb_substr((string) $row['seo_title_ar'], 0, 160),
                'seo_description_en' => $row['seo_description_en'] === null ? null : mb_substr((string) $row['seo_description_en'], 0, 320),
                'seo_description_ar' => $row['seo_description_ar'] === null ? null : mb_substr((string) $row['seo_description_ar'], 0, 320),
                'is_published' => (bool) $row['is_active'] && $row['deleted_at'] === null,
                'is_placeholder' => false,
                ...$this->timestamps($row),
            ];
            $existing = DB::table('static_pages')->where('slug', $slug)->where('country_id', $countryId)->first();

            if ($existing !== null && $this->ids->find($table, $id, MigrationContext::CENTRAL, 'static_pages') === null) {
                if ($existing->is_placeholder && ! $this->context->dryRun) {
                    DB::table('static_pages')->where('id', $existing->id)->update(array_diff_key($values, ['created_at' => 1]));
                }

                $this->writer->link($table, $id, MigrationContext::CENTRAL, 'static_pages', (int) $existing->id, $existing->is_placeholder ? 'merged' : 'linked');

                continue;
            }

            $this->writer->upsert($table, $id, MigrationContext::CENTRAL, 'static_pages', $values);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function page(string $table, array $row, array $blocks, string $code, string $locale): void
    {
        $id = (string) $row['id'];
        $key = $id.':'.$locale;
        $zone = [];
        $flagged = false;

        foreach ($blocks as $block) {
            $config = (array) json_decode((string) $block['config'], true);
            $converted = BlockTypeMap::convert((string) $block['block_type'], $config, $locale, $this->blockListings((string) $block['id'], $code));
            $flagged = $flagged || $converted['flagged'];
            $zone[] = ['id' => (string) $block['id'], 'type' => $converted['type'], 'settings' => $converted['settings'] + array_filter([
                'is_visible' => (bool) $block['is_visible'],
                'visible_from' => $block['visible_from'],
                'visible_until' => $block['visible_until'],
                'device' => $block['device_target'],
            ], fn ($value): bool => $value !== null)];
        }

        $template = in_array($row['page_type'], ['home', 'store_landing', 'category', 'campaign', 'brand_store', 'seller_store', 'search', 'vertical_landing', 'app_context'], true) ? $row['page_type'] : 'home';
        $zones = ['main' => $zone];
        $status = $flagged ? 'draft' : (in_array($row['status'], ['published', 'scheduled', 'draft', 'archived'], true) ? $row['status'] : 'draft');
        $pageId = $this->writer->upsert($table, $key, $code, 'pages', [
            'template' => $template,
            'slug' => $this->writer->unique($code, 'pages', 'slug', (string) $row['slug'], $this->ids->find($table, $key, $code, 'pages'), ['locale' => $locale], 120),
            'locale' => $locale,
            'title' => (string) ($row['name'] ?: Str::headline((string) $row['slug'])),
            'status' => $status,
            'publish_at' => $row['publish_at'],
            'published_at' => $status === 'published' ? ($row['published_at'] ?? $row['updated_at']) : null,
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'] === null ? null : mb_substr((string) $row['seo_description'], 0, 500),
            'legacy_id' => $id,
            ...$this->timestamps($row),
        ]);
        $revisionId = $this->writer->upsert($table, $key, $code, 'page_revisions', [
            'page_id' => $pageId,
            'number' => 1,
            'zones' => $zones,
            'note' => 'Imported from the old platform (version '.$row['version'].')'.($flagged ? ' — blocks to review' : ''),
            'created_at' => $row['updated_at'] ?? now(),
        ], countMetrics: false);

        if ($flagged) {
            $this->context->count($table, 'imported_as_draft_for_review');
        }

        if (! $this->context->dryRun) {
            MigrationContext::db($code)->table('pages')->where('id', $pageId)->update([
                'current_revision_id' => $revisionId,
                'published_revision_id' => $status === 'published' ? $revisionId : null,
            ]);
        }
    }

    /**
     * Migrated listings of a block's chosen product variants, in order.
     *
     * @return array<int, int>
     */
    private function blockListings(string $blockId, string $code): array
    {
        $variantIds = $this->source->connection()->table('page_block_products')->where('page_block_id', $blockId)->orderBy('position')->pluck('product_variant_id');
        $listingIds = [];

        foreach ($variantIds as $variantId) {
            $variant = $this->ids->find('product_variants', (string) $variantId, $code, 'product_variants');
            $listing = ($variant === null || $variant < 1) ? null : MigrationContext::db($code)->table('listings')->where('product_variant_id', $variant)->value('id');

            if ($listing !== null && ! in_array((int) $listing, $listingIds, true)) {
                $listingIds[] = (int) $listing;
            }
        }

        return $listingIds;
    }

    private function collections(string $table, string $hub, string $audience): void
    {
        foreach ($this->rows($table) as $row) {
            foreach ($this->countriesOf($table, $row) as $code) {
                $slug = mb_substr((string) $row['slug'], 0, 80);
                $this->writer->upsert($table, (string) $row['id'], $code, 'help_collections', [
                    'hub' => $hub,
                    'audience' => $audience,
                    'slug' => $slug,
                    'title_en' => $this->bilingual($row, 'name', 'en'),
                    'title_ar' => $this->bilingual($row, 'name', 'ar'),
                    'description_en' => $row['description_en'] ?? $row['description'] ?? null,
                    'description_ar' => $row['description_ar'] ?? null,
                    'sort_order' => (int) $row['sort_order'],
                    ...$this->timestamps($row),
                ], ['hub' => $hub, 'slug' => $slug]);
            }
        }
    }

    private function articles(string $table, string $hub, string $parentTable, string $parentColumn): void
    {
        foreach ($this->rows($table) as $row) {
            $id = (string) $row['id'];

            foreach ($this->countriesOf($table, $row) as $code) {
                $collectionId = $this->ids->find($parentTable, (string) $row[$parentColumn], $code, 'help_collections');

                if ($collectionId === null) {
                    $this->quarantine->put($table, $id, 'parent_unmigrated', "collection not migrated to {$code}", $code, $row);

                    continue;
                }

                $this->writer->upsert($table, $id, $code, 'help_articles', [
                    'collection_id' => $collectionId,
                    'hub' => $hub,
                    'slug' => $this->writer->unique($code, 'help_articles', 'slug', (string) $row['slug'], $this->ids->find($table, $id, $code, 'help_articles'), ['hub' => $hub], 160),
                    'title_en' => $this->bilingual($row, 'title', 'en'),
                    'title_ar' => $this->bilingual($row, 'title', 'ar'),
                    'body_en' => (string) ($row['body_en'] ?? $row['body'] ?? $row['body_ar'] ?? ''),
                    'body_ar' => (string) ($row['body_ar'] ?? $row['body'] ?? $row['body_en'] ?? ''),
                    'is_pinned' => (bool) $row['is_featured'],
                    'is_published' => $row['status'] === 'published' && $row['deleted_at'] === null,
                    'sort_order' => 0,
                    'views' => (int) $row['views_count'],
                    ...$this->timestamps($row),
                ]);
            }
        }
    }

    /**
     * Content without a country is global: every target country.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function countriesOf(string $table, array $row): array
    {
        if (! array_key_exists('country_id', $row) || $row['country_id'] === null || $row['country_id'] === '') {
            return $this->context->targetCountries();
        }

        $code = $this->singleCountry($table, $row);

        return $code === null ? [] : [$code];
    }
}
