<?php

namespace App\Domains\Content\Http\Controllers\Admin;

use App\Domains\Content\Models\NotificationTemplate;
use App\Domains\Content\Models\NotificationTemplateVersion;
use App\Domains\Content\Support\NotificationEvents;
use App\Domains\Content\Support\NotificationTemplates;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminAccess;
use App\Http\Controllers\Controller;
use App\Shared\Notifications\Email\EmailCatalogue;
use App\Shared\Notifications\Email\EmailRenderer;
use App\Shared\Notifications\Email\Emails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Content → Notification templates (ADR 10, settings catalogue
 * `notification_templates`): every email catalogue event × channel ×
 * language, for this country or as the global default. Variables are
 * checked on save; the mail channel previews in the branded layout with
 * sample data, sends a test to the signed-in admin, resets to the built-in
 * text and keeps a version history that can be restored.
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
        $catalogue = EmailCatalogue::find($event);
        $text = $stored !== null ? ['subject' => $stored->subject, 'body' => $stored->body] : ($definition['defaults'][$locale] ?? $definition['defaults']['en']);

        return view('panels.admin.content.notification-templates', [
            'events' => NotificationEvents::all(),
            'grouped' => EmailCatalogue::byActor(),
            'event' => $event,
            'channel' => $channel,
            'locale' => $locale,
            'definition' => $definition,
            'catalogue' => $catalogue,
            'stored' => $stored,
            'scope' => $stored === null ? 'default' : ($stored->country_id === null ? 'global' : 'country'),
            'text' => $text,
            'preview' => $this->templates->render($event, $channel, $locale, $this->sample($event), $country),
            'previewHtml' => $channel === 'mail' && $catalogue !== null ? $this->previewHtml($event, $locale, $text) : null,
            'versions' => $this->templates->versions($event, $channel, $locale, $stored?->country_id === null && $stored !== null ? null : $country),
            'overrides' => NotificationTemplate::query()->where('country_id', $country->id)->orderBy('event')->get(),
            'country' => $country,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate([
            'scope' => ['required', Rule::in(['country', 'global'])],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->authorizeScope($request, $data['scope']);
        $this->templates->save($data['event'], $data['channel'], $data['locale'], $data, $data['scope'] === 'country' ? $this->countryContext->current() : null, $request->user()->id);

        return $this->back($data)->with('status', __('Template saved.'));
    }

    /**
     * The unsaved editor text in the branded layout (iframe target).
     */
    public function preview(Request $request): Response
    {
        $data = $this->validated($request);
        abort_if(EmailCatalogue::find($data['event']) === null, 404);

        return response((string) $this->previewHtml($data['event'], $data['locale'], ['subject' => $data['subject'] ?? '', 'body' => $data['body']]))
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src * data:");
    }

    /**
     * Send the editor text with sample data to the signed-in admin.
     */
    public function test(Request $request, Emails $emails): RedirectResponse
    {
        $data = $this->validated($request);
        abort_if(EmailCatalogue::find($data['event']) === null, 404);
        $unknown = array_diff($this->templates->variablesIn(($data['subject'] ?? '').' '.$data['body']), EmailCatalogue::get($data['event'])['variables']);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => __('Unknown variables: :list. Use: :allowed.', ['list' => implode(', ', $unknown), 'allowed' => implode(', ', EmailCatalogue::get($data['event'])['variables'])])]);
        }

        $sent = $emails->send($data['event'], $request->user(), EmailCatalogue::sample($data['event']), [
            'once' => false, 'test' => true, 'force_locale' => $data['locale'], 'channels' => ['mail'],
            'template_override' => ['subject' => '[TEST] '.($data['subject'] ?? ''), 'body' => $data['body']],
        ]);

        return $this->back($data)->with('status', $sent->isEmpty()
            ? __('The test email was not sent: your address is on the suppression list.')
            : __('Test email sent to :email.', ['email' => $request->user()->email]));
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(NotificationEvents::all()))],
            'channel' => ['required', Rule::in(NotificationEvents::CHANNELS)],
            'locale' => ['required', Rule::in(NotificationEvents::LOCALES)],
            'scope' => ['required', Rule::in(['country', 'global'])],
        ]);

        $this->authorizeScope($request, $data['scope']);
        $reset = $this->templates->reset($data['event'], $data['channel'], $data['locale'], $data['scope'] === 'country' ? $this->countryContext->current() : null, $request->user()->id);

        return $this->back($data)->with('status', $reset ? __('Template reset to the default text.') : __('This scope already uses the default text.'));
    }

    public function restore(Request $request, NotificationTemplateVersion $version): RedirectResponse
    {
        $country = $version->country_id === null ? null : $this->countryContext->current();
        abort_if($version->country_id !== null && $version->country_id !== $country?->id, 404);
        $this->authorizeScope($request, $version->country_id === null ? 'global' : 'country');

        $this->templates->restore($version, $country, $request->user()->id);

        return $this->back($version->only(['event', 'channel', 'locale']))->with('status', __('Version :version restored.', ['version' => $version->version]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'event' => ['required', Rule::in(array_keys(NotificationEvents::all()))],
            'channel' => ['required', Rule::in(NotificationEvents::CHANNELS)],
            'locale' => ['required', Rule::in(NotificationEvents::LOCALES)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    private function authorizeScope(Request $request, string $scope): void
    {
        if ($scope === 'global' && ! app(AdminAccess::class)->isSuperAdmin($request->user())) {
            abort(403, __('Only a super admin edits the global default.'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function back(array $data): RedirectResponse
    {
        return redirect()->route('admin.notification-templates', ['event' => $data['event'], 'channel' => $data['channel'], 'locale' => $data['locale']]);
    }

    /**
     * @return array<string, string>
     */
    private function sample(string $event): array
    {
        return EmailCatalogue::find($event) !== null
            ? EmailCatalogue::sample($event)
            : collect(NotificationEvents::find($event)['variables'] ?? [])->mapWithKeys(fn (string $variable): array => [$variable => '['.$variable.']'])->all();
    }

    /**
     * @param  array{subject: string|null, body: string}  $text
     */
    private function previewHtml(string $event, string $locale, array $text): ?string
    {
        return app(EmailRenderer::class)->render($event, $locale, $this->sample($event), ['url' => '#'], [
            'email' => 'preview@example.com',
            'template' => ['subject' => (string) ($text['subject'] ?? ''), 'body' => $text['body']],
        ])?->html;
    }
}
