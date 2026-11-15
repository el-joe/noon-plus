<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\AppContext;
use App\Domains\Content\Models\Page;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Validation\ValidationException;

/**
 * App contexts (ADR 16, MASTER_PLAN §14c): the customer app's super-app
 * switcher per country — Main, Super Mall, Food, Minutes, Souq, Travel,
 * Property, Live — each with a header theme, a home layout (an
 * `app_context` page) and a bottom tab bar.
 */
class AppContexts
{
    public const TAB_TARGETS = ['home', 'categories', 'deals', 'account', 'cart', 'search', 'orders', 'live', 'favourites', 'page'];

    /**
     * @return array<string, array{name_en: string, name_ar: string, icon: string, theme: array<string, string>}>
     */
    public static function defaults(): array
    {
        return [
            'main' => ['name_en' => 'Nawy', 'name_ar' => 'ناوي', 'icon' => 'home', 'theme' => ['header_bg' => '#FEEE00', 'header_fg' => '#1B1B1B', 'accent' => '#3866DF']],
            'super_mall' => ['name_en' => 'Super Mall', 'name_ar' => 'سوبر مول', 'icon' => 'shopping-bag', 'theme' => ['header_bg' => '#1B1B1B', 'header_fg' => '#FFFFFF', 'accent' => '#C9A227']],
            'food' => ['name_en' => 'Food', 'name_ar' => 'طعام', 'icon' => 'utensils', 'theme' => ['header_bg' => '#E4382C', 'header_fg' => '#FFFFFF', 'accent' => '#E4382C']],
            'minutes' => ['name_en' => 'Minutes', 'name_ar' => 'دقائق', 'icon' => 'timer', 'theme' => ['header_bg' => '#0F9D58', 'header_fg' => '#FFFFFF', 'accent' => '#0F9D58']],
            'souq' => ['name_en' => 'Souq', 'name_ar' => 'السوق', 'icon' => 'store', 'theme' => ['header_bg' => '#3866DF', 'header_fg' => '#FFFFFF', 'accent' => '#3866DF']],
            'travel' => ['name_en' => 'Travel', 'name_ar' => 'سفر', 'icon' => 'plane', 'theme' => ['header_bg' => '#0B6E99', 'header_fg' => '#FFFFFF', 'accent' => '#0B6E99']],
            'property' => ['name_en' => 'Property', 'name_ar' => 'عقارات', 'icon' => 'building', 'theme' => ['header_bg' => '#2F4858', 'header_fg' => '#FFFFFF', 'accent' => '#2F4858']],
            'live' => ['name_en' => 'Live', 'name_ar' => 'مباشر', 'icon' => 'radio', 'theme' => ['header_bg' => '#7B2CBF', 'header_fg' => '#FFFFFF', 'accent' => '#7B2CBF']],
        ];
    }

    /**
     * Create the default contexts of the current country that are missing.
     */
    public function ensureDefaults(): void
    {
        $sort = 0;

        foreach (self::defaults() as $key => $context) {
            AppContext::query()->firstOrCreate(['key' => $key], $context + [
                'sort_order' => $sort++,
                'is_active' => true,
                'tabs' => [
                    ['key' => 'home', 'label_en' => 'Home', 'label_ar' => 'الرئيسية', 'icon' => 'home', 'target' => 'home'],
                    ['key' => 'categories', 'label_en' => 'Categories', 'label_ar' => 'الفئات', 'icon' => 'grid', 'target' => 'categories'],
                    ['key' => 'deals', 'label_en' => 'Deals', 'label_ar' => 'العروض', 'icon' => 'tag', 'target' => 'deals'],
                    ['key' => 'account', 'label_en' => 'Account', 'label_ar' => 'حسابي', 'icon' => 'user', 'target' => 'account'],
                    ['key' => 'cart', 'label_en' => 'Cart', 'label_ar' => 'السلة', 'icon' => 'cart', 'target' => 'cart'],
                ],
            ]);
        }
    }

    /**
     * @param  array{name_en: string, name_ar: string, icon?: string|null, sort_order: int, is_active: bool, theme: array<string, string>, tabs: array<int, array<string, string>>, home_page_id?: int|null}  $data
     */
    public function update(AppContext $context, array $data): AppContext
    {
        $tabs = array_values($data['tabs']);

        if (count($tabs) < 2 || count($tabs) > 5) {
            throw ValidationException::withMessages(['tabs' => __('A tab bar has 2 to 5 tabs.')]);
        }

        foreach ($tabs as $tab) {
            if (! in_array($tab['target'] ?? null, self::TAB_TARGETS, true)) {
                throw ValidationException::withMessages(['tabs' => __('Unknown tab target ":target".', ['target' => $tab['target'] ?? ''])]);
            }
        }

        foreach (['header_bg', 'header_fg', 'accent'] as $color) {
            if (! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($data['theme'][$color] ?? ''))) {
                throw ValidationException::withMessages(['theme' => __('Colours are hex values like #FEEE00.')]);
            }
        }

        if (! empty($data['home_page_id'])) {
            $page = Page::query()->findOrFail($data['home_page_id']);

            if ($page->template !== PageTemplate::AppContext) {
                throw new TransitionRefused(__('The home layout must be an app-context page.'));
            }
        }

        if ($context->key === 'main' && ! $data['is_active']) {
            throw new TransitionRefused(__('The main context cannot be switched off.'));
        }

        $context->update(['tabs' => $tabs, 'home_page_id' => $data['home_page_id'] ?? null] + array_intersect_key($data, array_flip(['name_en', 'name_ar', 'icon', 'sort_order', 'is_active', 'theme'])));

        return $context;
    }
}
