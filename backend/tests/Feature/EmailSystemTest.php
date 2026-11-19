<?php

use App\Domains\Content\Models\NotificationTemplate;
use App\Domains\Content\Models\NotificationTemplateVersion;
use App\Domains\Content\Support\NotificationEvents;
use App\Domains\Content\Support\NotificationTemplates;
use App\Models\User;
use App\Shared\Notifications\Email\EmailAddress;
use App\Shared\Notifications\Email\EmailCatalogue;
use App\Shared\Notifications\Email\EmailCategory;
use App\Shared\Notifications\Email\EmailPreferences;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\Models\EmailLog;
use App\Shared\Notifications\Email\Models\EmailSuppression;
use App\Shared\Notifications\Email\UnsubscribeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('omn');
});

it('has EN and AR defaults with only declared variables for every catalogue event, and documents them all', function () {
    $templates = app(NotificationTemplates::class);
    $document = (string) file_get_contents(base_path('../docs/project/email-catalogue.md'));

    expect(count(EmailCatalogue::all()))->toBeGreaterThanOrEqual(130);

    foreach (EmailCatalogue::all() as $key => $event) {
        expect($event['category'])->toBeInstanceOf(EmailCategory::class)
            ->and(array_keys(EmailCatalogue::ACTORS))->toContain($event['actor'])
            ->and(NotificationEvents::find($key))->not->toBeNull()
            ->and($document)->toContain('`'.$key.'`');

        foreach (['en', 'ar'] as $locale) {
            $text = $event['defaults'][$locale];
            expect($text['subject'])->not->toBe('')->and($text['body'])->not->toBe('')
                ->and(array_diff($templates->variablesIn($text['subject'].' '.$text['body']), $event['variables']))->toBe([]);
        }

        if ($event['category'] === EmailCategory::AdminAlerts) {
            expect($event['permission'])->not->toBeNull();
        }
    }
});

it('sends every catalogue event in English and Arabic with its sample data', function () {
    $en = User::factory()->create(['email' => 'en@example.com', 'preferred_locale' => 'en']);
    $ar = User::factory()->create(['email' => 'ar@example.com', 'preferred_locale' => 'ar']);

    foreach (array_keys(EmailCatalogue::all()) as $event) {
        app(Emails::class)->send($event, [$en, $ar], EmailCatalogue::sample($event), ['test' => true, 'channels' => ['mail'], 'url' => 'https://example.test/x']);

        $arabic = sentCatalogueEmails($event, 'ar@example.com')->first();
        expect(sentCatalogueEmails($event, 'en@example.com'))->toHaveCount(1, $event)
            ->and($arabic)->not->toBeNull()
            ->and($arabic->getSubject())->toBe(app(NotificationTemplates::class)->render($event, 'mail', 'ar', EmailCatalogue::sample($event))['subject'])
            ->and($arabic->getHtmlBody())->toContain('dir="rtl"')
            ->and((string) $arabic->getTextBody())->not->toBe('');
    }

    expect(EmailLog::query()->where('status', 'sent')->count())->toBe(count(EmailCatalogue::all()) * 2);
});

it('renders the order receipt with 3-decimal totals, RTL Arabic and a plain-text part', function () {
    $user = User::factory()->create(['email' => 'sara@example.com', 'name' => 'Sara', 'preferred_locale' => 'ar']);

    app(Emails::class)->send('order.placed', $user, ['order_number' => 'NW-OM-1', 'total' => EmailValue::money('24.75', 'OMR'), 'payment_method' => EmailValue::t('Card'), 'shipment_count' => '1'], [
        'entity' => 'Order:1',
        'blocks' => [
            ['type' => 'table', 'columns' => ['Item', 'Qty', 'Price'], 'rows' => [['Earbuds', '1', EmailValue::money('22.5', 'OMR')]]],
            ['type' => 'totals', 'rows' => [['Subtotal', EmailValue::money('22.5', 'OMR')], ['Shipping', EmailValue::money('2.25', 'OMR')], ['Total', EmailValue::money('24.75', 'OMR')]]],
            ['type' => 'facts', 'title' => 'Delivery address', 'rows' => [['Address', 'Al Khuwair, Muscat']]],
        ],
    ]);

    $mail = sentCatalogueEmails('order.placed', 'sara@example.com')->sole();
    $html = html_entity_decode((string) $mail->getHtmlBody(), ENT_QUOTES);

    expect($mail->getSubject())->toBe('تم استلام الطلب NW-OM-1')
        ->and($html)->toContain('dir="rtl"')->toContain('OMR 24.750')->toContain('OMR 2.250')->toContain('OMR 22.500')->toContain(__('Subtotal', [], 'ar'))
        ->and((string) $mail->getTextBody())->toContain('OMR 24.750')
        ->and($mail->getHeaders()->has('List-Unsubscribe'))->toBeFalse();
    expect(EmailLog::query()->sole())->status->toBe('sent')->locale->toBe('ar')->recipient_masked->toBe('s***@e******.com')->template_version->toBe(0);
});

it('queues on the mail queue and sends an event about the same entity only once', function () {
    $user = User::factory()->create();

    Queue::fake();
    app(Emails::class)->send('refund.issued', $user, EmailCatalogue::sample('refund.issued'), ['entity' => 'Refund:5']);
    Queue::assertPushedOn('mail', SendQueuedNotifications::class);

    app(Emails::class)->send('refund.issued', $user, EmailCatalogue::sample('refund.issued'), ['entity' => 'Refund:5']);
    app(Emails::class)->send('refund.issued', $user, EmailCatalogue::sample('refund.issued'), ['entity' => 'Refund:6']);
    app(Emails::class)->send('support.ticket_reply', $user, EmailCatalogue::sample('support.ticket_reply'), ['entity' => 'Ticket:1', 'once' => false]);
    app(Emails::class)->send('support.ticket_reply', $user, EmailCatalogue::sample('support.ticket_reply'), ['entity' => 'Ticket:1', 'once' => false]);

    expect(EmailLog::query()->where('event', 'refund.issued')->count())->toBe(2)
        ->and(EmailLog::query()->where('event', 'support.ticket_reply')->count())->toBe(2);
});

it('lets users turn off reminders but never transactional or security mail', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $preferences = app(EmailPreferences::class);
    $preferences->set($user, 'reminders', false);

    app(Emails::class)->send('classified.expiring', $user, EmailCatalogue::sample('classified.expiring'), ['entity' => 'Ad:1']);
    app(Emails::class)->send('classified.expired', $user, EmailCatalogue::sample('classified.expired'), ['entity' => 'Ad:1']);

    expect(sentCatalogueEmails('classified.expiring'))->toHaveCount(0)
        ->and(EmailLog::query()->where('event', 'classified.expiring')->value('status'))->toBe('suppressed')
        ->and(sentCatalogueEmails('classified.expired'))->toHaveCount(1)
        ->and(fn () => $preferences->set($user, 'security', false))->toThrow(ValidationException::class)
        ->and($preferences->enabled($user, 'cart_reminders'))->toBeFalse();

    $token = $user->createToken('web')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/omn-en/me/email-preferences')->assertOk()
        ->assertJsonPath('data.categories.0.topic', 'transactional')->assertJsonPath('data.categories.0.locked', true);
    $this->withToken($token)->putJson('/api/v1/omn-en/me/email-preferences', ['preferences' => [['topic' => 'cart_reminders', 'enabled' => true], ['topic' => 'transactional', 'enabled' => false]]])->assertOk();

    expect($preferences->enabled($user, 'cart_reminders'))->toBeTrue()->and($preferences->enabled($user, 'transactional'))->toBeTrue();
});

it('adds one-click unsubscribe headers to optional mail and honours the signed token', function () {
    $user = User::factory()->create(['email' => 'shopper@example.com']);

    app(Emails::class)->send('review.request', $user, EmailCatalogue::sample('review.request'), ['entity' => 'SubOrder:1']);
    $mail = sentCatalogueEmails('review.request')->sole();

    expect($mail->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
    preg_match('#/api/v1/email/unsubscribe/([A-Za-z0-9_.-]+)>#', $mail->getHeaders()->get('List-Unsubscribe')->getBodyAsString(), $match);

    $this->getJson('/api/v1/email/unsubscribe/'.$match[1])->assertOk()->assertJsonPath('data.subscribed', true)->assertJsonPath('data.topic', 'recommendations');
    $this->postJson('/api/v1/email/unsubscribe/'.$match[1])->assertOk()->assertJsonPath('data.subscribed', false);
    expect(app(EmailPreferences::class)->enabled($user, 'recommendations'))->toBeFalse();
    $this->deleteJson('/api/v1/email/unsubscribe/'.$match[1])->assertOk()->assertJsonPath('data.subscribed', true);

    $this->postJson('/api/v1/email/unsubscribe/'.$match[1].'x')->assertNotFound();
    $locked = app(UnsubscribeTokens::class)->make(EmailAddress::hash('shopper@example.com'), 'transactional', $user->id);
    $this->postJson('/api/v1/email/unsubscribe/'.$locked)->assertNotFound();

    $guest = app(UnsubscribeTokens::class)->make(EmailAddress::hash('guest@example.com'), 'reminders');
    $this->post('/email/unsubscribe/'.$guest)->assertRedirect();
    expect(EmailSuppression::query()->where('topic', 'reminders')->where('reason', 'unsubscribed')->exists())->toBeTrue();
    app(Emails::class)->send('classified.expiring', 'guest@example.com', EmailCatalogue::sample('classified.expiring'), ['entity' => 'Ad:2']);
    expect(sentCatalogueEmails('classified.expiring'))->toHaveCount(0);
});

it('suppresses hard bounces and complaints from signed provider webhooks', function () {
    config(['nawy.mail.webhooks.enabled' => ['generic', 'mailgun', 'postmark'], 'nawy.mail.webhooks.generic.secret' => 's3cret', 'nawy.mail.webhooks.mailgun.signing_key' => 'mg-key', 'nawy.mail.webhooks.postmark.user' => 'pm', 'nawy.mail.webhooks.postmark.password' => 'pw']);
    $user = User::factory()->create(['email' => 'bouncy@example.com']);
    $log = app(Emails::class)->send('order.confirmed', $user, EmailCatalogue::sample('order.confirmed'), ['entity' => 'SubOrder:1'])->sole();

    $body = json_encode(['events' => [['type' => 'bounce', 'log_id' => $log->id, 'permanent' => true, 'reason' => '550 no such user']]]);
    $this->call('POST', '/api/v1/webhooks/email/generic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NAWY_SIGNATURE' => 'wrong'], $body)->assertUnauthorized();
    $this->call('POST', '/api/v1/webhooks/email/generic', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NAWY_SIGNATURE' => hash_hmac('sha256', $body, 's3cret')], $body)->assertOk();

    expect($log->refresh())->status->toBe('bounced')->failure_reason->toContain('550')
        ->and(EmailSuppression::query()->where('email_hash', EmailAddress::hash('bouncy@example.com'))->value('reason'))->toBe('hard_bounce');

    app(Emails::class)->send('order.shipped', $user, EmailCatalogue::sample('order.shipped'), ['entity' => 'SubOrder:1']);
    expect(EmailLog::query()->where('event', 'order.shipped')->value('status'))->toBe('suppressed');

    $timestamp = (string) time();
    $this->postJson('/api/v1/webhooks/email/mailgun', ['signature' => ['timestamp' => $timestamp, 'token' => 'tok', 'signature' => hash_hmac('sha256', $timestamp.'tok', 'mg-key')],
        'event-data' => ['event' => 'complained', 'recipient' => 'angry@example.com']])->assertOk();
    $this->postJson('/api/v1/webhooks/email/mailgun', ['signature' => ['timestamp' => $timestamp, 'token' => 'tok', 'signature' => 'bad'], 'event-data' => ['event' => 'complained']])->assertUnauthorized();
    $this->withBasicAuth('pm', 'pw')->postJson('/api/v1/webhooks/email/postmark', ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'soft@example.com'])->assertOk();
    $this->postJson('/api/v1/webhooks/email/ses', [])->assertNotFound();

    expect(EmailSuppression::query()->where('email_hash', EmailAddress::hash('angry@example.com'))->value('reason'))->toBe('complaint')
        ->and(EmailSuppression::query()->where('email_hash', EmailAddress::hash('soft@example.com'))->exists())->toBeFalse();
});

it('previews, test-sends, versions, resets and restores templates in the admin editor', function () {
    $admin = adminAccount();
    $admin->update(['email' => 'admin@example.com']);
    $base = ['event' => 'order.shipped', 'channel' => 'mail', 'locale' => 'en'];

    $this->actingAs($admin)->get(onPanel('admin', 'notification-templates?event=order.shipped'))->assertOk()->assertSee('Version history')->assertSee('srcdoc', false);
    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates/preview'), $base + ['subject' => 'Parcel {{ shipment_number }}', 'body' => 'Hello {{ name }}'])
        ->assertOk()->assertSee('Parcel NW-OM-1042-1')->assertSee('Hello Sara');
    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates/test'), $base + ['subject' => 'Parcel {{ shipment_number }}', 'body' => 'Hello'])->assertRedirect()->assertSessionHas('status');
    expect(sentCatalogueEmails('order.shipped', 'admin@example.com')->sole()->getSubject())->toBe('[TEST] Parcel NW-OM-1042-1');
    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates/test'), $base + ['body' => 'Hi {{ nope }}'])->assertSessionHasErrors('body');

    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates'), $base + ['scope' => 'country', 'subject' => 'First {{ shipment_number }}', 'body' => 'One', 'is_active' => 1])->assertRedirect();
    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates'), $base + ['scope' => 'country', 'subject' => 'Second', 'body' => 'Two', 'is_active' => 1])->assertRedirect();
    expect(NotificationTemplate::query()->sole()->version)->toBe(2);

    $first = NotificationTemplateVersion::query()->where('version', 1)->sole();
    $this->actingAs($admin)->post(onPanel('admin', "notification-templates/versions/{$first->id}/restore"))->assertRedirect();
    expect(NotificationTemplate::query()->sole())->subject->toBe('First {{ shipment_number }}')->version->toBe(3);

    app(Emails::class)->send('order.shipped', User::factory()->create(['email' => 'c@example.com']), EmailCatalogue::sample('order.shipped'), ['entity' => 'SubOrder:9']);
    expect(EmailLog::query()->where('recipient_hash', EmailAddress::hash('c@example.com'))->sole()->template_version)->toBe(3);

    $this->actingAs($admin)->post(onPanel('admin', 'notification-templates/reset'), $base + ['scope' => 'country'])->assertRedirect();
    expect(NotificationTemplate::query()->count())->toBe(0)
        ->and(NotificationTemplateVersion::query()->latest('id')->first()->action)->toBe('reset');
});

it('shows the email log with resend and suppression management, and admin alert opt-in by permission', function () {
    $admin = adminAccount();
    $user = User::factory()->create(['email' => 'log@example.com']);
    $log = app(Emails::class)->send('dispute.resolved', $user, EmailCatalogue::sample('dispute.resolved'), ['entity' => 'Dispute:1'])->sole();
    app(Emails::class)->send('auth.email_code', $user, EmailCatalogue::sample('auth.email_code'));

    $this->actingAs($admin)->get(onPanel('admin', 'email-log'))->assertOk()->assertSee('l**@e******.com')->assertSee('dispute.resolved');
    $this->actingAs($admin)->get(onPanel('admin', 'email-log?recipient=log@example.com&status=sent'))->assertOk()->assertSee('dispute.resolved');
    $this->actingAs($admin)->post(onPanel('admin', "email-log/{$log->id}/resend"))->assertRedirect();
    expect(EmailLog::query()->where('resent_from_id', $log->id)->count())->toBe(1)
        ->and(EmailLog::query()->where('event', 'auth.email_code')->sole()->canResend())->toBeFalse();

    $this->actingAs($admin)->post(onPanel('admin', 'email-log/suppressions'), ['email' => 'spam@example.com'])->assertRedirect();
    $suppression = EmailSuppression::query()->sole();
    $this->actingAs($admin)->delete(onPanel('admin', "email-log/suppressions/{$suppression->id}"))->assertRedirect();
    expect(EmailSuppression::query()->count())->toBe(0);

    $this->actingAs($admin)->get(onPanel('admin', 'account/email-preferences'))->assertOk()->assertSee('Payment under review');
    $this->actingAs($admin)->post(onPanel('admin', 'account/email-preferences'), ['topics' => ['admin:admin.payment_under_review']])->assertRedirect();
    app(Emails::class)->alertAdmins('admin.payment_under_review', EmailCatalogue::sample('admin.payment_under_review'), ['entity' => 'Payment:1']);
    app(Emails::class)->alertAdmins('admin.dispute_opened', EmailCatalogue::sample('admin.dispute_opened'), ['entity' => 'Dispute:1']);

    expect(sentCatalogueEmails('admin.payment_under_review', $admin->email))->toHaveCount(1)
        ->and(sentCatalogueEmails('admin.dispute_opened'))->toHaveCount(0);
});
