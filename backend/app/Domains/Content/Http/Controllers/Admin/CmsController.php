<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\BlogCategory;
use App\Domains\Content\Models\BlogPost;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HelpCollection;
use App\Domains\Content\Models\StaticPage;
use App\Domains\Content\Support\BlogPosts;
use App\Domains\Content\Support\Footer;
use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Orders\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\StateMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Content CMS (SRS §15, PORTAL_PAGES): blog (nested categories,
 * bilingual posts, tags, featured, scheduled publishing), help centre and
 * Ads Knowledge Hub (collections and pinned articles), static pages
 * (country or global) and the storefront footer per language.
 */
class CmsController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private CountryContext $countryContext, private CountryClock $clock) {}

    public function blog(): View
    {
        return view('panels.admin.content.blog', [
            'posts' => BlogPost::query()->with('category')->latest('id')->paginate(25),
            'categories' => BlogCategory::query()->orderBy('sort_order')->get(),
            'country' => $this->countryContext->current(),
            'clock' => $this->clock,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists(BlogCategory::class, 'id')],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ]);
        BlogCategory::query()->create($data + ['slug' => $this->uniqueSlug(BlogCategory::class, $data['name_en']), 'sort_order' => 0]);

        return back()->with('status', __('Category added.'));
    }

    public function storePost(Request $request): RedirectResponse
    {
        $data = $this->validatePost($request);
        $post = BlogPost::query()->create($data + ['slug' => $this->uniqueSlug(BlogPost::class, $data['title_en']), 'author_user_id' => $request->user()->id, 'status' => PageStatus::Draft]);
        app(StateMachine::class)->created($post, ActorType::Admin, $request->user()->id);

        return back()->with('status', __('Draft post saved.'));
    }

    public function updatePost(Request $request, int $post): RedirectResponse
    {
        BlogPost::query()->findOrFail($post)->update($this->validatePost($request));

        return back()->with('status', __('Post saved.'));
    }

    public function postAction(Request $request, int $post, string $action, BlogPosts $posts): RedirectResponse
    {
        $post = BlogPost::query()->findOrFail($post);
        $userId = $request->user()->id;

        match ($action) {
            'publish' => $posts->publish($post, ActorType::Admin, $userId),
            'schedule' => $posts->schedule($post, $this->clock->localToUtc($this->countryContext->current(), $request->validate(['publish_at' => ['required', 'date_format:'.self::LOCAL_FORMAT]])['publish_at']), $userId),
            'draft' => $posts->move($post, PageStatus::Draft, $userId),
            'archive' => $posts->move($post, PageStatus::Archived, $userId),
        };

        return back()->with('status', __('Post :status.', ['status' => $post->status->value]));
    }

    public function help(Request $request): View
    {
        $hub = $request->validate(['hub' => ['nullable', Rule::in(HelpCollection::HUBS)]])['hub'] ?? 'help_center';

        return view('panels.admin.content.help', [
            'hub' => $hub,
            'collections' => HelpCollection::query()->where('hub', $hub)->with('articles')->orderBy('sort_order')->get(),
            'country' => $this->countryContext->current(),
        ]);
    }

    public function storeCollection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hub' => ['required', Rule::in(HelpCollection::HUBS)],
            'audience' => ['required', Rule::in(HelpCollection::AUDIENCES)],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ]);
        HelpCollection::query()->create($data + ['slug' => $this->uniqueSlug(HelpCollection::class, $data['title_en'], ['hub' => $data['hub']]), 'sort_order' => 0]);

        return back()->with('status', __('Collection added.'));
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        $data = $this->validateArticle($request);
        $collection = HelpCollection::query()->findOrFail($data['collection_id']);
        HelpArticle::query()->create($data + ['hub' => $collection->hub, 'slug' => $this->uniqueSlug(HelpArticle::class, $data['title_en'], ['hub' => $collection->hub])]);

        return back()->with('status', __('Article saved.'));
    }

    public function updateArticle(Request $request, int $article): RedirectResponse
    {
        $article = HelpArticle::query()->findOrFail($article);
        $data = $this->validateArticle($request);
        $collection = HelpCollection::query()->where('hub', $article->hub)->findOrFail($data['collection_id']);
        $article->update($data + ['collection_id' => $collection->id]);

        return back()->with('status', __('Article saved.'));
    }

    public function staticPages(): View
    {
        $country = $this->countryContext->current();

        return view('panels.admin.content.static-pages', [
            'pages' => StaticPage::query()->where(fn ($query) => $query->where('country_id', $country->id)->orWhereNull('country_id'))->orderBy('slug')->orderBy('country_id')->get(),
            'country' => $country,
        ]);
    }

    public function saveStaticPage(Request $request, AdminAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'scope' => ['required', Rule::in(['country', 'global'])],
            'audience' => ['required', Rule::in(StaticPage::AUDIENCES)],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'body_en' => ['required', 'string', 'max:100000'],
            'body_ar' => ['required', 'string', 'max:100000'],
            'seo_title_en' => ['nullable', 'string', 'max:160'],
            'seo_title_ar' => ['nullable', 'string', 'max:160'],
            'seo_description_en' => ['nullable', 'string', 'max:320'],
            'seo_description_ar' => ['nullable', 'string', 'max:320'],
            'is_published' => ['required', 'boolean'],
        ]);

        abort_if($data['scope'] === 'global' && ! $access->isSuperAdmin($request->user()), 403, __('Only a super admin edits the global page.'));

        StaticPage::query()->updateOrCreate(
            ['country_id' => $data['scope'] === 'country' ? $this->countryContext->current()->id : null, 'slug' => $data['slug']],
            collect($data)->except(['scope', 'slug'])->all() + ['is_placeholder' => false, 'updated_by' => $request->user()->id],
        );

        return back()->with('status', __('Static page saved.'));
    }

    public function footer(Request $request, Footer $footer): View
    {
        $locale = $request->validate(['locale' => ['nullable', Rule::in($this->countryContext->current()->locales)]])['locale'] ?? 'en';

        return view('panels.admin.content.footer', [
            'footer' => $footer->get($this->countryContext->current(), $locale),
            'locale' => $locale,
            'networks' => Footer::NETWORKS,
            'country' => $this->countryContext->current(),
        ]);
    }

    public function saveFooter(Request $request, Footer $footer): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in($this->countryContext->current()->locales)],
            'columns' => ['nullable', 'array', 'max:6'],
            'columns.*.title' => ['nullable', 'string', 'max:60'],
            'columns.*.links' => ['nullable', 'string', 'max:3000'],
            'social' => ['nullable', 'array'],
            'social.*' => ['nullable', 'url', 'max:255'],
            'copyright' => ['nullable', 'string', 'max:255'],
        ]);
        $columns = collect($data['columns'] ?? [])->filter(fn (array $column): bool => ! empty($column['title']))
            ->map(fn (array $column): array => ['title' => $column['title'], 'links' => Footer::parseLinks((string) ($column['links'] ?? ''))])->values()->all();
        $social = collect($data['social'] ?? [])->only(Footer::NETWORKS)->filter()->all();
        $footer->save($this->countryContext->current(), $data['locale'], ['columns' => $columns, 'social' => $social, 'copyright' => $data['copyright'] ?? null], $request->user()->id);

        return back()->with('status', __('Footer saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePost(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists(BlogCategory::class, 'id')],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'excerpt_en' => ['nullable', 'string', 'max:500'],
            'excerpt_ar' => ['nullable', 'string', 'max:500'],
            'body_en' => ['required', 'string', 'max:100000'],
            'body_ar' => ['required', 'string', 'max:100000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'is_featured' => ['nullable', 'boolean'],
            'cover' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn (string $tag): string => Str::lower(trim($tag)))->filter()->unique()->values()->all();
        $data['is_featured'] = (bool) ($data['is_featured'] ?? false);

        if ($request->hasFile('cover')) {
            $data['cover_path'] = $request->file('cover')->store('content/'.$this->countryContext->current()->code.'/blog', 'public');
        }

        unset($data['cover']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateArticle(Request $request): array
    {
        $data = $request->validate([
            'collection_id' => ['required', 'integer'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'body_en' => ['required', 'string', 'max:100000'],
            'body_ar' => ['required', 'string', 'max:100000'],
            'is_pinned' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
        ]);

        return ['is_pinned' => (bool) ($data['is_pinned'] ?? false), 'is_published' => (bool) ($data['is_published'] ?? false), 'sort_order' => (int) ($data['sort_order'] ?? 0)] + $data;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $scope
     */
    private function uniqueSlug(string $model, string $title, array $scope = []): string
    {
        $base = Str::limit(Str::slug($title), 70, '') ?: 'item';
        $slug = $base;

        for ($suffix = 2; $model::query()->where($scope)->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
