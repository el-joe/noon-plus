<?php

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\AppContext;
use App\Domains\Content\Models\BlogCategory;
use App\Domains\Content\Models\BlogPost;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HelpCollection;
use App\Domains\Content\Models\NotificationTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageRevision;
use App\Domains\Content\Models\StaticPage;
use App\Domains\Content\Support\AppContexts;
use App\Domains\Content\Support\BlockRegistry;
use App\Domains\Content\Support\BlogPosts;
use App\Domains\Content\Support\NotificationTemplates;
use App\Domains\Content\Support\Pages;
use App\Domains\Countries\Models\Country;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    $this->pages = app(Pages::class);
});

/**
 * A home page (en) with one complete hero carousel block in its draft.
 *
 * @param  array<string, mixed>  $zones
 */
function homePage(array $zones = [], string $slug = 'home'): Page
{
    $page = app(Pages::class)->create(['template' => 'home', 'slug' => $slug, 'locale' => 'en', 'title' => 'Home'], 1);
    app(Pages::class)->saveDraft($page, $zones ?: ['main' => [['type' => 'hero_carousel', 'settings' => ['slides' => [['image_d' => 'hero.webp', 'link' => '/deals']]]]]], 1);

    return $page->refresh();
}

it('registers exactly the 51 block types of the page-builder registry document', function () {
    preg_match_all('/^\| \d+ \| `([a-z_]+)`/m', file_get_contents(base_path('../docs/design/page-builder-blocks.md')), $documented);

    expect(BlockRegistry::all())->toHaveCount(51)
        ->and(array_keys(BlockRegistry::all()))->toEqualCanonicalizing($documented[1])
        ->and(BlockRegistry::allowedIn('category_header', 'header'))->toBeTrue()
        ->and(BlockRegistry::allowedIn('category_header', 'main'))->toBeFalse()
        ->and(BlockRegistry::allowedIn('product_listing', 'listing'))->toBeTrue()
        ->and(BlockRegistry::allowedIn('product_listing', 'top'))->toBeFalse()
        ->and(BlockRegistry::allowedIn('subcategory_tiles', 'header'))->toBeTrue()
        ->and(BlockRegistry::allowedIn('sponsored_product', 'listing'))->toBeTrue()
        ->and(BlockRegistry::missing('banner_full', ['ad_slot_key' => 'home_top']))->toBe([])
        ->and(BlockRegistry::missing('countdown_strip', ['text' => 'Hurry']))->toBe(['ends_at']);
});

it('refuses unknown blocks, wrong zones and headers inside containers', function () {
    $page = homePage();

    expect(fn () => $this->pages->saveDraft($page, ['main' => [['type' => 'carousel_3000']]], 1))->toThrow(ValidationException::class)
        ->and(fn () => $this->pages->saveDraft($page, ['main' => [['type' => 'category_header']]], 1))->toThrow(ValidationException::class)
        ->and(fn () => $this->pages->saveDraft($page, ['listing' => [['type' => 'product_listing']]], 1))->toThrow(ValidationException::class)
        ->and(fn () => $this->pages->saveDraft($page, ['main' => [['type' => 'section_band', 'settings' => ['children' => [['type' => 'brand_header']]]]]], 1))->toThrow(ValidationException::class);

    $category = $this->pages->create(['template' => 'category', 'slug' => 'electronics', 'locale' => 'en', 'title' => 'Electronics'], 1);
    $revision = $this->pages->saveDraft($category, ['header' => [['type' => 'category_header'], ['type' => 'subcategory_tiles']], 'listing' => [['type' => 'subcategory_chips'], ['type' => 'product_listing', 'settings' => ['scope' => 'category']]]], 1);
    expect($revision->zones['header'])->toHaveCount(2)->and($revision->zones['header'][0]['id'])->toBeString()->and($revision->zones)->toHaveKeys(['top', 'bottom']);
});

it('publishes only complete pages and serves the published revision with resolved blocks', function () {
    $listing = sellableListing('75.00', vendor: Vendor::factory()->create());
    $page = homePage(['main' => [['type' => 'hero_carousel', 'settings' => []]]]);

    expect($this->pages->publishProblems($page))->toBe(['main #1 hero_carousel needs slides'])
        ->and(fn () => $this->pages->publish($page, ActorType::Admin, 1))->toThrow(TransitionRefused::class, 'Fix before publishing');
    $this->getJson('/api/v1/uae-en/pages/home')->assertNotFound();

    $this->pages->saveDraft($page, ['main' => [
        ['type' => 'hero_carousel', 'settings' => ['slides' => [['image_d' => 'a.webp']]]],
        ['type' => 'product_carousel', 'settings' => ['source' => 'manual', 'listing_ids' => [$listing->id]]],
        ['type' => 'banner_strip', 'settings' => ['image_d' => 'later.webp', 'visibility' => ['starts_at' => now()->addDay()->toIso8601String()]]],
        ['type' => 'live_streams_strip'],
    ]], 1);
    $this->pages->publish($page->refresh(), ActorType::Admin, 1);

    $this->getJson('/api/v1/uae-en/pages/home')->assertOk()
        ->assertJsonPath('data.template', 'home')
        ->assertJsonCount(3, 'data.zones.main')
        ->assertJsonPath('data.zones.main.1.data.products.0.listing_id', $listing->id)
        ->assertJsonPath('data.zones.main.2.type', 'live_streams_strip');
    $this->getJson('/api/v1/uae-ar/pages/home')->assertNotFound();
    $this->getJson('/api/v1/ksa-en/pages/home')->assertNotFound();
    $this->actAsCountry('uae');

    $this->pages->saveDraft($page, ['main' => [['type' => 'seo_text', 'settings' => ['text' => 'New draft']]]], 1);
    $this->getJson('/api/v1/uae-en/pages/home')->assertJsonCount(3, 'data.zones.main');

    $this->pages->publish($page->refresh(), ActorType::Admin, 1);
    expect($page->refresh()->status)->toBe(PageStatus::Published);
    $this->getJson('/api/v1/uae-en/pages/home')->assertJsonCount(1, 'data.zones.main')->assertJsonPath('data.zones.main.0.type', 'seo_text');

    $this->pages->archive($page, 1);
    $this->getJson('/api/v1/uae-en/pages/home')->assertNotFound();
});

it('keeps revisions append-only and restores by copying', function () {
    $page = homePage();
    $first = $page->currentRevision;
    $this->pages->saveDraft($page, ['main' => [['type' => 'seo_text', 'settings' => ['text' => 'B']]]], 1);

    expect(fn () => $first->update(['note' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);

    $restored = $this->pages->restore($page->refresh(), $first->number, 1);
    expect($restored->number)->toBe(4)
        ->and($restored->zones['main'][0]['type'])->toBe('hero_carousel')
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe(4)
        ->and($page->refresh()->current_revision_id)->toBe($restored->id);
});

it('publishes scheduled pages and blog posts from the 5-minute job', function () {
    $page = homePage();
    expect(fn () => $this->pages->schedule($page, CarbonImmutable::now()->subMinute(), 1))->toThrow(TransitionRefused::class);
    $this->pages->schedule($page, CarbonImmutable::now()->addMinutes(30), 1);

    $post = BlogPost::query()->create(['slug' => 'eid', 'title_en' => 'Eid', 'title_ar' => 'عيد', 'body_en' => 'x', 'body_ar' => 'س']);
    app(BlogPosts::class)->schedule($post, CarbonImmutable::now()->addMinutes(10), 1);

    $this->artisan('nawy:content:publish-scheduled')->assertSuccessful();
    expect($page->refresh()->status)->toBe(PageStatus::Scheduled)->and($post->refresh()->status)->toBe(PageStatus::Scheduled);

    $this->travel(31)->minutes();
    $this->artisan('nawy:content:publish-scheduled')->assertSuccessful();
    expect($page->refresh()->status)->toBe(PageStatus::Published)
        ->and($page->published_revision_id)->toBe($page->current_revision_id)
        ->and($post->refresh()->status)->toBe(PageStatus::Published)
        ->and($post->published_at)->not->toBeNull();

    $other = homePage(slug: 'other');
    $this->pages->schedule($other, CarbonImmutable::now()->addDay(), 1);
    $this->pages->toDraft($other, 1);
    expect($other->refresh()->status)->toBe(PageStatus::Draft)->and($other->publish_at)->toBeNull();
});

it('copies a page to other countries as drafts', function () {
    $page = homePage();
    $ksa = Country::query()->where('code', 'ksa')->sole();

    expect($this->pages->copyToCountries($page, ['ksa', 'omn', 'uae'], 1))->toBe(2);

    $this->actAsCountry('ksa');
    $copy = Page::query()->where('slug', 'home')->where('locale', 'en')->sole();
    expect($copy->status)->toBe(PageStatus::Draft)->and($copy->currentRevision->zones['main'][0]['type'])->toBe('hero_carousel');

    $this->actAsCountry('uae');
    $this->pages->copyToCountries($page, ['ksa'], 1);
    $this->actAsCountry('ksa');
    expect(PageRevision::query()->where('page_id', $copy->id)->count())->toBe(3);
    $this->pages->create(['template' => 'campaign', 'slug' => 'sale', 'locale' => 'en', 'title' => 'Sale'], 1);

    $this->actAsCountry('uae');
    $sale = homePage(slug: 'sale');
    expect(fn () => $this->pages->copyToCountries($sale, [$ksa->code], 1))->toThrow(TransitionRefused::class, 'another template');
});

it('drives the page builder from the admin panel with content permissions', function () {
    $content = adminAccount('uae', 'content');

    $this->actingAs($content)->post(onPanel('admin', 'pages'), ['template' => 'home', 'slug' => 'home', 'locale' => 'en', 'title' => 'Home'])->assertRedirect();
    $page = Page::query()->sole();
    $this->actingAs($content)->get(onPanel('admin', "pages/{$page->id}"))->assertOk()->assertSee('Zone: main');

    $this->actingAs($content)->post(onPanel('admin', "pages/{$page->id}/save"), ['title' => 'Home', 'add' => ['main' => 'countdown_strip']])->assertRedirect();
    $this->actingAs($content)->post(onPanel('admin', "pages/{$page->id}/save"), ['title' => 'Home', 'blocks' => ['main' => [
        ['type' => 'countdown_strip', 'settings' => '{not json', 'order' => 1],
    ]]])->assertSessionHasErrors();
    $block = $page->refresh()->currentRevision->zones['main'][0];
    $this->actingAs($content)->post(onPanel('admin', "pages/{$page->id}/save"), ['title' => 'Home page', 'blocks' => ['main' => [
        ['id' => $block['id'], 'type' => 'countdown_strip', 'settings' => json_encode(['text' => 'Ends soon', 'ends_at' => '2026-12-01T00:00:00Z']), 'order' => 20],
        ['type' => 'seo_text', 'settings' => '{"text":"Hello"}', 'order' => 10],
    ]]])->assertRedirect();
    expect(collect($page->refresh()->currentRevision->zones['main'])->pluck('type')->all())->toBe(['seo_text', 'countdown_strip'])
        ->and($page->title)->toBe('Home page');

    $this->actingAs($content)->post(onPanel('admin', "pages/{$page->id}/publish"))->assertRedirect();
    expect($page->refresh()->status)->toBe(PageStatus::Published);

    $this->actingAs($content)->post(onPanel('admin', "pages/{$page->id}/copy"), ['countries' => ['omn']])->assertSessionHas('status');

    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'pages'))->assertForbidden();
    $marketing = adminAccount('uae', 'marketing');
    $this->actingAs($marketing)->get(onPanel('admin', 'pages'))->assertOk();
    $this->actingAs($marketing)->get(onPanel('admin', 'app-contexts'))->assertForbidden();
});

it('defines the eight app contexts per country with theme, home layout and tabs', function () {
    $this->getJson('/api/v1/uae-en/app/contexts')->assertOk()->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.key', 'main')
        ->assertJsonPath('data.0.tabs.4.target', 'cart');
    $this->getJson('/api/v1/uae-ar/app/contexts')->assertJsonPath('data.2.name', 'طعام');

    $food = AppContext::query()->where('key', 'food')->sole();
    $home = $this->pages->create(['template' => 'app_context', 'slug' => 'food-home', 'locale' => 'en', 'title' => 'Food'], 1);
    $this->pages->saveDraft($home, ['main' => [['type' => 'seo_text', 'settings' => ['text' => 'Hungry?']]]], 1);
    $this->pages->publish($home->refresh(), ActorType::Admin, 1);
    $contexts = app(AppContexts::class);
    $valid = ['name_en' => 'Food', 'name_ar' => 'طعام', 'sort_order' => 1, 'is_active' => true, 'theme' => ['header_bg' => '#FF0000', 'header_fg' => '#FFFFFF', 'accent' => '#FF0000'], 'tabs' => $food->tabs, 'home_page_id' => $home->id];

    expect(fn () => $contexts->update($food, ['tabs' => [$food->tabs[0]]] + $valid))->toThrow(ValidationException::class)
        ->and(fn () => $contexts->update($food, ['theme' => ['header_bg' => 'red', 'header_fg' => '#FFFFFF', 'accent' => '#000000']] + $valid))->toThrow(ValidationException::class)
        ->and(fn () => $contexts->update($food, ['home_page_id' => homePage()->id] + $valid))->toThrow(TransitionRefused::class)
        ->and(fn () => $contexts->update(AppContext::query()->where('key', 'main')->sole(), ['is_active' => false] + $valid))->toThrow(TransitionRefused::class);

    $contexts->update($food, $valid);
    $food = collect($this->getJson('/api/v1/uae-en/app/contexts')->json('data'))->firstWhere('key', 'food');
    expect($food['home_page'])->toBe('food-home')->and($food['theme']['header_bg'])->toBe('#FF0000');
    $food = AppContext::query()->where('key', 'food')->sole();

    $this->actingAs(adminAccount('uae', 'content'))->put(onPanel('admin', "app-contexts/{$food->id}"), ['is_active' => 0, 'tabs' => [['label_en' => 'Home', 'target' => 'home'], ['label_en' => 'Cart', 'target' => 'cart']]] + $valid)->assertRedirect();
    $this->getJson('/api/v1/uae-en/app/contexts')->assertJsonCount(7, 'data');

    $this->actAsCountry('ksa');
    expect(AppContext::query()->count())->toBe(0);
    $this->getJson('/api/v1/ksa-en/app/contexts')->assertJsonCount(8, 'data');
});

it('runs the blog with categories, tags, featured posts, views and related posts', function () {
    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->post(onPanel('admin', 'cms/blog/categories'), ['name_en' => 'Selling tips', 'name_ar' => 'نصائح البيع'])->assertRedirect();
    $categoryId = BlogCategory::query()->value('id');

    foreach (['How to price', 'Better photos', 'Ship faster', 'Ramadan ideas', 'Draft only'] as $index => $title) {
        $this->actingAs($content)->post(onPanel('admin', 'cms/blog/posts'), ['title_en' => $title, 'title_ar' => 'عنوان '.$index, 'body_en' => 'Body', 'body_ar' => 'نص', 'category_id' => $categoryId, 'tags' => 'Pricing, tips', 'is_featured' => $index === 0 ? 1 : 0])->assertRedirect();
    }

    BlogPost::query()->where('title_en', '!=', 'Draft only')->get()->each(fn (BlogPost $post) => $this->actingAs($content)->post(onPanel('admin', "cms/blog/posts/{$post->id}/publish"))->assertRedirect());

    $this->getJson('/api/v1/uae-en/blog')->assertOk()->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/uae-en/blog?featured=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'how-to-price');
    $this->getJson('/api/v1/uae-en/blog?tag=pricing&category=selling-tips')->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/uae-en/blog/how-to-price')->assertOk()->assertJsonPath('data.views', 1)->assertJsonCount(3, 'data.related');
    $this->getJson('/api/v1/uae-ar/blog/how-to-price')->assertJsonPath('data.title', 'عنوان 0');
    $this->getJson('/api/v1/uae-en/blog/draft-only')->assertNotFound();
    $this->getJson('/api/v1/ksa-en/blog')->assertJsonPath('meta.total', 0);

    $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'cms'))->assertForbidden();
});

it('searches the help centre in both languages, most viewed first, and keeps the knowledge hub apart', function () {
    $help = HelpCollection::query()->create(['hub' => 'help_center', 'audience' => 'customer', 'slug' => 'orders', 'title_en' => 'Orders', 'title_ar' => 'الطلبات']);
    $hub = HelpCollection::query()->create(['hub' => 'knowledge_hub', 'audience' => 'seller', 'slug' => 'ads', 'title_en' => 'Ads', 'title_ar' => 'إعلانات']);
    HelpArticle::query()->create(['collection_id' => $help->id, 'hub' => 'help_center', 'slug' => 'track', 'title_en' => 'Track my delivery', 'title_ar' => 'تتبع التوصيل', 'body_en' => 'Use the code', 'body_ar' => 'استخدم الرمز', 'is_published' => true, 'views' => 5]);
    HelpArticle::query()->create(['collection_id' => $help->id, 'hub' => 'help_center', 'slug' => 'late', 'title_en' => 'Late delivery', 'title_ar' => 'تأخر التوصيل', 'body_en' => 'Sorry', 'body_ar' => 'نأسف', 'is_published' => true, 'views' => 50, 'is_pinned' => true]);
    HelpArticle::query()->create(['collection_id' => $help->id, 'hub' => 'help_center', 'slug' => 'hidden', 'title_en' => 'Delivery draft', 'title_ar' => 'مسودة', 'body_en' => 'x', 'body_ar' => 'x', 'is_published' => false]);
    HelpArticle::query()->create(['collection_id' => $hub->id, 'hub' => 'knowledge_hub', 'slug' => 'cpc', 'title_en' => 'Delivery of ads', 'title_ar' => 'عرض الإعلانات', 'body_en' => 'CPC', 'body_ar' => 'CPC', 'is_published' => true]);

    $this->getJson('/api/v1/uae-en/help/help_center/search?q=delivery')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.slug', 'late');
    $this->getJson('/api/v1/uae-ar/help/help_center/search?q='.urlencode('التوصيل'))->assertJsonCount(2, 'data')->assertJsonPath('data.1.title', 'تتبع التوصيل');
    $this->getJson('/api/v1/uae-en/help/knowledge_hub/collections')->assertJsonCount(1, 'data')->assertJsonPath('data.0.articles.0.slug', 'cpc');
    $this->getJson('/api/v1/uae-en/help/help_center/collections')->assertJsonPath('data.0.articles.0.slug', 'late')->assertJsonPath('data.0.articles_count', 2);
    $this->getJson('/api/v1/uae-en/help/help_center/articles/track')->assertOk()->assertJsonPath('data.body', 'Use the code');
    $this->getJson('/api/v1/uae-en/help/help_center/articles/hidden')->assertNotFound();
    $this->getJson('/api/v1/uae-en/help/other/search?q=xx')->assertNotFound();
});

it('serves static pages with a country override over the global page', function () {
    $super = adminAccount();
    $ops = adminAccount('uae', 'content');
    $page = ['slug' => 'terms', 'audience' => 'both', 'title_en' => 'Terms', 'title_ar' => 'الشروط', 'body_en' => 'Global terms', 'body_ar' => 'الشروط العامة', 'is_published' => 1];

    $this->actingAs($ops)->post(onPanel('admin', 'cms/static-pages'), ['scope' => 'global'] + $page)->assertForbidden();
    $this->actingAs($super)->post(onPanel('admin', 'cms/static-pages'), ['scope' => 'global'] + $page)->assertRedirect();
    $this->getJson('/api/v1/ksa-en/static-pages/terms')->assertOk()->assertJsonPath('data.body', 'Global terms')->assertJsonPath('data.country_specific', false);

    $this->actingAs($ops)->post(onPanel('admin', 'cms/static-pages'), ['scope' => 'country', 'body_en' => 'UAE terms'] + $page)->assertRedirect();
    $this->getJson('/api/v1/uae-en/static-pages/terms')->assertJsonPath('data.body', 'UAE terms')->assertJsonPath('data.country_specific', true);
    $this->getJson('/api/v1/ksa-en/static-pages/terms')->assertJsonPath('data.body', 'Global terms');

    StaticPage::query()->create(['slug' => 'seller-faq', 'audience' => 'portal', 'title_en' => 'FAQ', 'title_ar' => 'أسئلة', 'body_en' => 'x', 'body_ar' => 'x']);
    $this->getJson('/api/v1/uae-en/static-pages/seller-faq')->assertNotFound();
    $this->getJson('/api/v1/uae-en/static-pages/seller-faq?audience=portal')->assertOk();
});

it('saves the footer per country and language from the admin panel', function () {
    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->post(onPanel('admin', 'cms/footer'), ['locale' => 'en', 'social' => ['instagram' => 'not a url']])->assertSessionHasErrors('social.instagram');

    $this->actingAs($content)->post(onPanel('admin', 'cms/footer'), [
        'locale' => 'en',
        'columns' => [['title' => 'Help', 'links' => "Contact | /contact\nBad link | javascript:alert(1)\nBlog | https://nawy.test/blog"], ['title' => '', 'links' => 'x | /y']],
        'social' => ['instagram' => 'https://instagram.com/nawy', 'myspace' => 'https://myspace.com/nawy'],
        'copyright' => '© Nawy',
    ])->assertRedirect();

    $this->getJson('/api/v1/uae-en/footer')->assertOk()
        ->assertJsonCount(1, 'data.columns')
        ->assertJsonCount(2, 'data.columns.0.links')
        ->assertJsonPath('data.social', ['instagram' => 'https://instagram.com/nawy'])
        ->assertJsonPath('data.copyright', '© Nawy');
    $this->getJson('/api/v1/uae-ar/footer')->assertJsonCount(0, 'data.columns');
    $this->getJson('/api/v1/ksa-en/footer')->assertJsonCount(0, 'data.columns');
});

it('renders notification templates per country and language with checked variables', function () {
    $templates = app(NotificationTemplates::class);
    $vars = ['customer_name' => 'Sara', 'order_number' => 'NW-1', 'shipment_number' => 'NW-1-1', 'delivery_code' => '4821', 'agent_name' => 'Omar', 'cod_amount' => ''];
    $ksa = Country::query()->where('code', 'ksa')->sole();

    expect($templates->render('delivery.out_for_delivery', 'mail', 'ar', $vars, $this->country)['body'])->toContain('4821')->toContain('Sara');

    expect(fn () => $templates->save('delivery.out_for_delivery', 'mail', 'en', ['body' => 'Code {{ secret_token }}'], null, 1))->toThrow(ValidationException::class);

    $templates->save('delivery.out_for_delivery', 'mail', 'en', ['subject' => 'Global {{ order_number }}', 'body' => 'Global code {{delivery_code}}'], null, 1);
    $templates->save('delivery.out_for_delivery', 'mail', 'en', ['subject' => 'UAE {{ order_number }}', 'body' => 'UAE code {{ delivery_code }} for {{ customer_name }}'], $this->country, 1);

    expect($templates->render('delivery.out_for_delivery', 'mail', 'en', $vars, $this->country))->toBe(['subject' => 'UAE NW-1', 'body' => 'UAE code 4821 for Sara'])
        ->and($templates->render('delivery.out_for_delivery', 'mail', 'en', $vars, $ksa))->toBe(['subject' => 'Global NW-1', 'body' => 'Global code 4821'])
        ->and($templates->render('unknown.event', 'mail', 'en', $vars))->toBeNull();

    $templates->save('delivery.out_for_delivery', 'sms', 'en', ['body' => 'off', 'is_active' => false], $this->country, 1);
    expect($templates->render('delivery.out_for_delivery', 'sms', 'en', $vars, $this->country))->toBeNull();

    $content = adminAccount('uae', 'content');
    $this->actingAs($content)->get(onPanel('admin', 'notification-templates?event=delivery.failed&locale=ar'))->assertOk()->assertSee('تعذر');
    $this->actingAs($content)->post(onPanel('admin', 'notification-templates'), ['event' => 'delivery.failed', 'channel' => 'mail', 'locale' => 'en', 'scope' => 'country', 'subject' => 'Missed {{ shipment_number }}', 'body' => 'Reason: {{ reason }}', 'is_active' => 1])->assertRedirect();
    $this->actingAs($content)->post(onPanel('admin', 'notification-templates'), ['event' => 'delivery.failed', 'channel' => 'mail', 'locale' => 'en', 'scope' => 'global', 'body' => 'x', 'is_active' => 1])->assertForbidden();
    expect(NotificationTemplate::query()->where('event', 'delivery.failed')->sole()->country_id)->toBe($this->country->id);

    $this->actingAs(adminAccount('uae', 'operations'))->get(onPanel('admin', 'notification-templates'))->assertForbidden();
});
