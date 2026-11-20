<?php

namespace App\Domains\Content\Http\Controllers\Api;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\AppContext;
use App\Domains\Content\Models\BlogCategory;
use App\Domains\Content\Models\BlogPost;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HelpArticleVote;
use App\Domains\Content\Models\HelpCollection;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\AppContexts;
use App\Domains\Content\Support\BlockResolver;
use App\Domains\Content\Support\Footer;
use App\Domains\Content\Support\PagePreview;
use App\Domains\Content\Support\Pages;
use App\Domains\Content\Support\StaticPages;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\LikeSearch;
use App\Shared\Http\PublicCache;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public content API for the storefront, portal and apps (SRS §15, ADR 16):
 * published pages with resolved blocks (cached per country + locale), app
 * contexts, blog, help centre / knowledge hub, static pages and footer.
 * The market sets country and language.
 */
class ContentApiController extends Controller
{
    /**
     * Seconds the small public answers (help collections, static pages, blog categories, app contexts) are kept; every change forgets them at once.
     */
    private const CACHE_SECONDS = 600;

    public function __construct(private CountryContext $countryContext) {}

    public function page(Request $request, string $slug, Pages $pages, BlockResolver $resolver, VerticalSettings $settings, PagePreview $preview): JsonResponse
    {
        $locale = app()->getLocale();
        $country = $this->countryContext->current();

        // Admin preview link (signed, expiring): the current draft of any status, never cached.
        if ($request->filled('preview') && $preview->valid((string) $request->query('preview'), $country, $slug)) {
            $page = Page::query()->where('slug', $slug)->where('locale', $locale)->with('currentRevision')->first();
            abort_if($page === null || $page->currentRevision === null, 404);
            // Unsaved edits of the editor (live preview while typing), else the saved draft.
            $draftKey = (string) $request->query('draft', '');
            $draft = preg_match('/^[a-f0-9-]{16,64}$/', $draftKey) === 1 ? Cache::get($preview->draftCacheKey($country, $slug, $draftKey)) : null;

            return ApiResponse::success([
                'slug' => $page->slug,
                'template' => $page->template->value,
                'title' => $page->title,
                'seo' => ['title' => $page->seo_title ?? $page->title, 'description' => $page->seo_description],
                'published_at' => $page->published_at?->toIso8601String(),
                'preview' => true,
                'zones' => $resolver->resolve(is_array($draft) ? $draft : $page->currentRevision->zones, null, $preview->showsHidden((string) $request->query('preview'), $country, $slug)),
            ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
        }

        $payload = Cache::remember($pages->cacheKey($country->code, $locale, $slug), now()->addMinutes($settings->int('content.page_cache_minutes')), function () use ($slug, $locale): ?array {
            $page = Page::query()->where('slug', $slug)->where('locale', $locale)->where('status', PageStatus::Published)->with('publishedRevision')->first();

            if ($page === null || $page->publishedRevision === null) {
                return null;
            }

            return [
                'slug' => $page->slug,
                'template' => $page->template->value,
                'title' => $page->title,
                'seo' => ['title' => $page->seo_title ?? $page->title, 'description' => $page->seo_description],
                'published_at' => $page->published_at?->toIso8601String(),
                'zones' => $page->publishedRevision->zones,
            ];
        });

        abort_if($payload === null, 404);

        $payload['zones'] = $resolver->resolve($payload['zones']);

        return ApiResponse::success($payload);
    }

    public function appContexts(AppContexts $contexts): JsonResponse
    {
        $ar = app()->getLocale() === 'ar';

        return ApiResponse::success(PublicCache::remember(PublicCache::countryGroup(PublicCache::APP_CONTEXTS), app()->getLocale(), self::CACHE_SECONDS, fn (): array => $this->buildAppContexts($contexts, $ar)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAppContexts(AppContexts $contexts, bool $ar): array
    {
        $contexts->ensureDefaults();

        return AppContext::query()->where('is_active', true)->with('homePage')->orderBy('sort_order')->get()->map(fn (AppContext $context): array => [
            'key' => $context->key,
            'name' => $ar ? $context->name_ar : $context->name_en,
            'icon' => $context->icon,
            'theme' => $context->theme,
            'home_page' => $context->homePage?->status === PageStatus::Published ? $context->homePage->slug : null,
            'tabs' => collect($context->tabs)->map(fn (array $tab): array => [
                'key' => $tab['key'] ?? $tab['target'],
                'label' => $ar ? ($tab['label_ar'] ?? '') : ($tab['label_en'] ?? ''),
                'icon' => $tab['icon'] ?? null,
                'target' => $tab['target'],
                'page' => $tab['page'] ?? null,
            ])->all(),
        ])->all();
    }

    public function blog(Request $request, VerticalSettings $settings): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:80'],
            'tag' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
        ]);
        $ar = app()->getLocale() === 'ar';

        $posts = BlogPost::query()->where('status', PageStatus::Published)->with('category')
            ->when($filters['category'] ?? null, fn ($query, string $slug) => $query->whereHas('category', fn ($category) => $category->where('slug', $slug)))
            ->when($filters['tag'] ?? null, fn ($query, string $tag) => $query->whereJsonContains('tags', $tag))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(fn ($search) => $search->where('title_en', 'like', LikeSearch::contains($term))->orWhere('title_ar', 'like', LikeSearch::contains($term))))
            ->when($filters['featured'] ?? false, fn ($query) => $query->where('is_featured', true))
            ->latest('published_at')->paginate($settings->int('content.blog_per_page'));

        return ApiResponse::success($posts->getCollection()->map(fn (BlogPost $post): array => $this->postSummary($post, $ar))->all(), [
            'current_page' => $posts->currentPage(), 'page' => $posts->currentPage(), 'last_page' => $posts->lastPage(), 'total' => $posts->total(), 'per_page' => $posts->perPage(),
        ]);
    }

    public function blogCategories(): JsonResponse
    {
        [$categories, $tree] = PublicCache::remember(PublicCache::countryGroup(PublicCache::BLOG_CATEGORIES), app()->getLocale(), self::CACHE_SECONDS, fn (): array => $this->buildBlogCategories());

        return ApiResponse::success($categories, ['tree' => $tree]);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function buildBlogCategories(): array
    {
        $ar = app()->getLocale() === 'ar';

        $categories = BlogCategory::query()->orderBy('sort_order')->orderBy('id')->get();
        $counts = BlogPost::query()->where('status', PageStatus::Published)->whereNotNull('category_id')->selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id');
        $node = fn (BlogCategory $category): array => [
            'id' => $category->id, 'slug' => $category->slug, 'name' => $ar ? $category->name_ar : $category->name_en, 'parent_id' => $category->parent_id,
            'post_count' => (int) ($counts[$category->id] ?? 0),
        ];
        $tree = function (?int $parentId, int $depth) use (&$tree, $categories, $node): array {
            return $depth > 5 ? [] : $categories->where('parent_id', $parentId)->values()->map(fn (BlogCategory $category): array => $node($category) + ['children' => $tree($category->id, $depth + 1)])->all();
        };

        return [$categories->map($node)->all(), $tree(null, 0)];
    }

    public function blogPost(string $slug, VerticalSettings $settings): JsonResponse
    {
        $post = BlogPost::query()->where('slug', $slug)->where('status', PageStatus::Published)->with('category')->firstOrFail();
        $post->increment('views');
        $ar = app()->getLocale() === 'ar';
        $related = BlogPost::query()->where('status', PageStatus::Published)->whereKeyNot($post->id)
            ->when($post->category_id, fn ($query) => $query->where('category_id', $post->category_id))
            ->latest('published_at')->limit($settings->int('content.blog_related_count'))->get();

        return ApiResponse::success($this->postSummary($post, $ar) + [
            'body' => $ar ? $post->body_ar : $post->body_en,
            'views' => $post->views,
            'related' => $related->map(fn (BlogPost $other): array => $this->postSummary($other, $ar))->all(),
        ]);
    }

    public function helpCollections(Request $request, string $hub): JsonResponse
    {
        $filters = $request->validate(['audience' => ['nullable', Rule::in(HelpCollection::AUDIENCES)]]);
        $ar = app()->getLocale() === 'ar';

        return ApiResponse::success(PublicCache::remember(
            PublicCache::countryGroup(PublicCache::HELP),
            'collections:'.$hub.':'.($filters['audience'] ?? 'all').':'.app()->getLocale(),
            self::CACHE_SECONDS,
            fn (): array => HelpCollection::query()->where('hub', $hub)
                ->when($filters['audience'] ?? null, fn ($query, string $audience) => $query->where('audience', $audience))
                ->with(['articles' => fn ($query) => $query->where('is_published', true)])
                ->orderBy('sort_order')->get()->map(fn (HelpCollection $collection): array => [
                    'slug' => $collection->slug,
                    'title' => $ar ? $collection->title_ar : $collection->title_en,
                    'description' => $ar ? $collection->description_ar : $collection->description_en,
                    'audience' => $collection->audience,
                    'articles_count' => $collection->articles->count(),
                    'articles' => $collection->articles->map(fn (HelpArticle $article): array => $this->articleSummary($article, $ar))->all(),
                ])->all(),
        ));
    }

    /**
     * Search EN + AR titles and bodies, most viewed first (top 30).
     */
    public function helpSearch(Request $request, string $hub, VerticalSettings $settings): JsonResponse
    {
        $term = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $ar = app()->getLocale() === 'ar';

        return ApiResponse::success(HelpArticle::query()->where('hub', $hub)->where('is_published', true)
            ->where(fn ($query) => $query->where('title_en', 'like', LikeSearch::contains($term))->orWhere('title_ar', 'like', LikeSearch::contains($term))
                ->orWhere('body_en', 'like', LikeSearch::contains($term))->orWhere('body_ar', 'like', LikeSearch::contains($term)))
            ->orderByDesc('views')->limit($settings->int('content.help_search_limit'))->get()
            ->map(fn (HelpArticle $article): array => $this->articleSummary($article, $ar))->all());
    }

    public function helpArticle(string $hub, string $slug): JsonResponse
    {
        $article = HelpArticle::query()->where('hub', $hub)->where('slug', $slug)->where('is_published', true)->with('collection')->firstOrFail();
        $article->increment('views');
        $ar = app()->getLocale() === 'ar';

        return ApiResponse::success($this->articleSummary($article, $ar) + [
            'body' => $ar ? $article->body_ar : $article->body_en,
            'collection' => $article->collection->slug,
            'collection_detail' => [
                'slug' => $article->collection->slug,
                'title' => $ar ? $article->collection->title_ar : $article->collection->title_en,
                'audience' => $article->collection->audience,
            ],
            'helpful' => ['yes' => (int) $article->helpful_count, 'no' => (int) $article->not_helpful_count],
            'updated_at' => $article->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * "Was this helpful?" (G-W25): one answer per voter — the signed-in
     * customer, else the `X-Visitor-Token`, else the IP; answering again
     * switches the vote. Returns the new counts and the caller's vote.
     */
    public function voteHelpArticle(Request $request, string $hub, string $slug): JsonResponse
    {
        $helpful = (bool) $request->validate(['helpful' => ['required', 'boolean']])['helpful'];
        $article = HelpArticle::query()->where('hub', $hub)->where('slug', $slug)->where('is_published', true)->firstOrFail();
        $user = $request->user('sanctum');
        $visitor = (string) $request->header('X-Visitor-Token', '');
        $key = hash('sha256', $user !== null ? 'user:'.$user->id : ($visitor !== '' ? 'visitor:'.$visitor : 'ip:'.$request->ip()));

        DB::connection($article->getConnectionName())->transaction(function () use ($article, $key, $user, $helpful): void {
            $vote = HelpArticleVote::query()->where('help_article_id', $article->id)->where('voter_key', $key)->lockForUpdate()->first();

            if ($vote !== null && $vote->is_helpful === $helpful) {
                return;
            }

            if ($vote !== null) {
                HelpArticle::query()->whereKey($article->id)->decrement($vote->is_helpful ? 'helpful_count' : 'not_helpful_count');
                $vote->forceFill(['is_helpful' => $helpful])->save();
            } else {
                HelpArticleVote::query()->create(['help_article_id' => $article->id, 'voter_key' => $key, 'user_id' => $user?->id, 'is_helpful' => $helpful]);
            }

            HelpArticle::query()->whereKey($article->id)->increment($helpful ? 'helpful_count' : 'not_helpful_count');
        });

        $article->refresh();

        return ApiResponse::success(['helpful' => ['yes' => (int) $article->helpful_count, 'no' => (int) $article->not_helpful_count], 'my_vote' => $helpful]);
    }

    public function staticPage(Request $request, string $slug, StaticPages $pages): JsonResponse
    {
        $audience = $request->validate(['audience' => ['nullable', Rule::in(['storefront', 'portal'])]])['audience'] ?? 'storefront';
        $country = $this->countryContext->current();
        $locale = app()->getLocale();
        $payload = PublicCache::remember(PublicCache::STATIC_PAGES, implode(':', [$country->code, $audience, $locale, sha1($slug)]), self::CACHE_SECONDS, function () use ($slug, $country, $audience, $pages, $locale): array {
            $page = $pages->resolve($slug, $country, $audience);

            if ($page === null) {
                return [];
            }

            $ar = $locale === 'ar';
            $title = $ar ? $page->title_ar : $page->title_en;
            $body = $ar ? $page->body_ar : $page->body_en;

            return [
                'slug' => $page->slug,
                'title' => $title,
                'body' => $body,
                'seo' => [
                    'title' => ($ar ? $page->seo_title_ar : $page->seo_title_en) ?: $title,
                    'description' => ($ar ? $page->seo_description_ar : $page->seo_description_en) ?: Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $body))), 160, '…'),
                ],
                'country_specific' => $page->country_id !== null,
                // QA-032: seeded legal text that legal has not replaced yet.
                'placeholder' => (bool) $page->is_placeholder,
                'updated_at' => $page->updated_at?->toIso8601String(),
            ];
        });

        abort_if($payload === [], 404);

        return ApiResponse::success($payload);
    }

    /**
     * Footer columns come from settings, which SettingsService already caches (and forgets on save).
     */
    public function footer(Footer $footer): JsonResponse
    {
        return ApiResponse::success($footer->get($this->countryContext->current(), app()->getLocale()));
    }

    /**
     * @return array<string, mixed>
     */
    private function postSummary(BlogPost $post, bool $ar): array
    {
        return [
            'slug' => $post->slug,
            'title' => $ar ? $post->title_ar : $post->title_en,
            'excerpt' => $ar ? $post->excerpt_ar : $post->excerpt_en,
            'cover_url' => $post->cover_path === null ? null : Storage::disk('public')->url($post->cover_path),
            'category' => $post->category === null ? null : ['slug' => $post->category->slug, 'name' => $ar ? $post->category->name_ar : $post->category->name_en],
            'tags' => $post->tags ?? [],
            'is_featured' => $post->is_featured,
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleSummary(HelpArticle $article, bool $ar): array
    {
        return ['slug' => $article->slug, 'title' => $ar ? $article->title_ar : $article->title_en, 'is_pinned' => $article->is_pinned, 'views' => $article->views, 'helpful_count' => (int) $article->helpful_count];
    }
}
