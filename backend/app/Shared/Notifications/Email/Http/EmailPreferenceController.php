<?php

namespace App\Shared\Notifications\Email\Http;

use App\Domains\Panels\Support\Panel;
use App\Http\Controllers\Controller;
use App\Shared\Notifications\Email\EmailCategory;
use App\Shared\Notifications\Email\EmailPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email preferences. Customers: GET / PUT /api/v1/{market}/me/email-preferences
 * (website Account → Notification settings, apps). Panel users:
 * `{panel}.email-preferences` (account/email-preferences), with the admin
 * alerts their permissions allow.
 */
class EmailPreferenceController extends Controller
{
    /**
     * Categories a storefront customer can see.
     */
    public const CUSTOMER_CATEGORIES = ['transactional', 'security', 'reminders', 'recommendations', 'cart_reminders'];

    public function __construct(private EmailPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['categories' => $this->preferences->categoriesFor($request->user(), self::CUSTOMER_CATEGORIES)]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.topic' => ['required', 'string', 'in:'.implode(',', self::CUSTOMER_CATEGORIES)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $preference) {
            if (! EmailCategory::from($preference['topic'])->isLocked()) {
                $this->preferences->set($request->user(), $preference['topic'], (bool) $preference['enabled']);
            }
        }

        return $this->show($request);
    }

    public function panel(Request $request): View
    {
        $panel = Panel::find((string) $request->route('panel'));

        return view('panels.shared.email-preferences', [
            'panel' => $panel,
            'categories' => $this->preferences->categoriesFor($request->user(), self::panelCategories($panel)),
            'alerts' => $panel->key === 'admin' ? $this->preferences->adminAlertsFor($request->user()) : [],
        ]);
    }

    public function savePanel(Request $request): RedirectResponse
    {
        $panel = Panel::find((string) $request->route('panel'));
        $data = $request->validate(['topics' => ['array'], 'topics.*' => ['string', 'max:64']]);
        $chosen = $data['topics'] ?? [];
        $offered = array_merge(
            array_column(array_filter($this->preferences->categoriesFor($request->user(), self::panelCategories($panel)), fn (array $category): bool => ! $category['locked']), 'topic'),
            array_column($panel->key === 'admin' ? $this->preferences->adminAlertsFor($request->user()) : [], 'topic'),
        );

        foreach ($offered as $topic) {
            $this->preferences->set($request->user(), $topic, in_array($topic, $chosen, true));
        }

        return redirect()->route($panel->route('email-preferences'))->with('status', __('Email preferences saved.'));
    }

    /**
     * @return array<int, string>
     */
    private static function panelCategories(Panel $panel): array
    {
        return $panel->key === 'admin' ? ['transactional', 'security'] : ['transactional', 'security', 'reminders', 'opportunities'];
    }
}
