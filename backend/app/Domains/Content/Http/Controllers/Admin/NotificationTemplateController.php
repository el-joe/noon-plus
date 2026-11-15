<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Models\NotificationTemplate;
use App\Domains\Content\Support\NotificationEvents;
use App\Domains\Content\Support\NotificationTemplates;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Platform → Notification templates (ADR 10, settings catalogue
 * `notification_templates`): per event × channel × language, for this
 * country or as the global default; variables are checked on save and a
 * preview fills them with sample values.
 */
class NotificationTemplateController extends Controller
{
    public function __construct(private CountryContext $countryContext, private NotificationTemplates $templates) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'event' => ['nullable', Rule::in(array_keys(NotificationEvents::all()))],
            'channel' => ['nullable', Rule::in(NotificationEvents::CHANNELS)],
            'locale' => ['nullable', Rule::in(NotificationEvents::LOCALES)],
        ]);
        $event = $filters['event'] ?? array_key_first(NotificationEvents::all());
        $channel = $filters['channel'] ?? 'mail';
        $locale = $filters['locale'] ?? 'en';
        $country = $this->countryContext->current();
        $stored = $this->templates->stored($event, $channel, $locale, $country);
        $definition = NotificationEvents::find($event);

        return view('panels.admin.content.notification-templates', [
            'events' => NotificationEvents::all(),
            'event' => $event,
            'channel' => $channel,
            'locale' => $locale,
            'definition' => $definition,
            'stored' => $stored,
            'scope' => $stored === null ? 'default' : ($stored->country_id === null ? 'global' : 'country'),
            'text' => $stored !== null ? ['subject' => $stored->subject, 'body' => $stored->body] : ($definition['defaults'][$locale] ?? $definition['defaults']['en']),
            'preview' => $this->templates->render($event, $channel, $locale, collect($definition['variables'])->mapWithKeys(fn (string $variable): array => [$variable => '['.$variable.']'])->all(), $country),
            'overrides' => NotificationTemplate::query()->where('country_id', $country->id)->orderBy('event')->get(),
            'country' => $country,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(NotificationEvents::all()))],
            'channel' => ['required', Rule::in(NotificationEvents::CHANNELS)],
            'locale' => ['required', Rule::in(NotificationEvents::LOCALES)],
            'scope' => ['required', Rule::in(['country', 'global'])],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($data['scope'] === 'global' && ! app(AdminAccess::class)->isSuperAdmin($request->user())) {
            abort(403, __('Only a super admin edits the global default.'));
        }

        $this->templates->save($data['event'], $data['channel'], $data['locale'], $data, $data['scope'] === 'country' ? $this->countryContext->current() : null, $request->user()->id);

        return redirect()->route('admin.notification-templates', ['event' => $data['event'], 'channel' => $data['channel'], 'locale' => $data['locale']])->with('status', __('Template saved.'));
    }
}
