<?php

use App\Domains\Catalog\Support\ProductRequests;
use App\Domains\Delivery\Enums\AssignmentStatus;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Delivery\Models\DeliveryPayslip;
use App\Domains\Delivery\Support\AgentPay;
use App\Domains\Delivery\Support\DeliveryDispatch;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Enums\WithdrawalStatus;
use App\Domains\Marketers\Models\MarketerWithdrawal;
use App\Domains\Onboarding\Enums\ApplicationStatus;
use App\Domains\Onboarding\Enums\ApplicationType;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Support\ApplicationReview;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Panels\Support\TeamInvitations;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\Notifications\Email\EmailPreferences;
use App\Shared\Notifications\Email\EventEmail;
use App\Shared\Notifications\Email\Models\EmailLog;
use App\Shared\StateMachine\StateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    $this->withoutVite();
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function partnerEmailsApplication(array $attributes = []): PartnerApplication
{
    $application = PartnerApplication::query()->create($attributes + [
        'number' => 'PA-'.Str::upper(Str::random(8)),
        'type' => ApplicationType::Vendor,
        'status' => ApplicationStatus::Submitted,
        'token_hash' => hash('sha256', Str::random(16)),
        'email' => 'sara.applicant@example.com',
        'name' => 'Sara',
        'phone' => '+971501234567',
        'password_hash' => Hash::make('secret-pass-1'),
        'locale' => 'ar',
        'data' => ['store' => ['store_name' => 'SoundMax', 'slug' => 'soundmax-'.Str::lower(Str::random(4))], 'address' => ['city' => 'Dubai']],
    ]);
    $application->forceFill(['email_verified_at' => now()])->save();

    return $application;
}

/**
 * Messages actually delivered (MAIL_MAILER=array) for a catalogue event,
 * optionally to one address. Mail::fake() does not see a Mailable returned
 * by a notification's toMail(), so the real transport is read instead.
 *
 * @return Collection<int, Email>
 */
function partnerEmailsSent(string $event, ?string $to = null): Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
        ->filter(fn (Email $mail): bool => $mail->getHeaders()->get('X-Nawy-Event')?->getBodyAsString() === $event
            && ($to === null || collect($mail->getTo())->contains(fn (Address $address): bool => $address->getAddress() === $to)))
        ->values();
}

function partnerEmailHtml(Email $mail): string
{
    return html_entity_decode((string) $mail->getHtmlBody(), ENT_QUOTES);
}

it('emails the applicant in the application language when the application is approved, with the welcome', function () {
    $application = partnerEmailsApplication();

    app(ApplicationReview::class)->approve($application, adminAccount()->id);

    $mail = partnerEmailsSent('partner.application_approved', 'sara.applicant@example.com')->sole();
    expect($mail->getSubject())->toBe('تمت الموافقة على طلبك في ناوي')
        ->and(partnerEmailHtml($mail))->toContain('partner.')
        ->and(partnerEmailsSent('partner.welcome', 'sara.applicant@example.com'))->toHaveCount(1)
        ->and(EmailLog::query()->where('event', 'partner.application_approved')->sole()->entity_id)->toBe((string) $application->id);
});

it('mails a team invitation with the accept link through the catalogue', function () {
    [$owner, $vendor] = sellerAccount();

    app(TeamInvitations::class)->invite('partner', PanelCountries::VENDOR_TENANT, $vendor->id, 'SoundMax', MembershipRole::ProductVendor, 'finance', 'huda@example.com', 'Huda', [], $owner->id);

    $mail = partnerEmailsSent('team.invitation', 'huda@example.com')->sole();
    expect($mail->getSubject())->toBe('You are invited to join SoundMax on Nawy')
        ->and(partnerEmailHtml($mail))->toContain('team-invitations')->toContain('Huda');
    expect(EmailLog::query()->where('event', 'team.invitation')->sole())->status->toBe('sent')->payload->toBeNull();
});

it('emails the store owner when a payout is paid, once', function () {
    [$owner, $vendor] = sellerAccount();
    $payout = VendorPayout::query()->create(['number' => 'ST-2026-00001', 'vendor_id' => $vendor->id, 'currency' => 'AED', 'status' => PayoutStatus::Processing, 'schedule' => 'monthly',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'total' => '250.00', 'bank_reference' => 'BANK-77']);

    app(StateMachine::class)->move($payout, PayoutStatus::Completed, ActorType::Admin, 1);

    expect(partnerEmailHtml(partnerEmailsSent('payout.paid', $owner->email)->sole()))->toContain('ST-2026-00001')->toContain('BANK-77')
        ->and(EmailLog::query()->where('event', 'payout.paid')->count())->toBe(1);
});

it('emails the invoice with its PDF and tells the store when the auto-charge fails', function () {
    [$owner, $vendor] = sellerAccount();
    $plan = VendorSubscriptionPlan::query()->create(['code' => 'pro', 'name_en' => 'Pro', 'name_ar' => 'احترافي', 'interval' => 'monthly', 'price' => '105.00', 'trial_days' => 0, 'is_active' => true]);
    $subscriptions = app(VendorSubscriptions::class);
    $subscriptions->subscribe($vendor, $plan, SubscriptionPaymentPreference::Card, ActorType::Vendor, $owner->id);
    $invoice = VendorSubscriptionInvoice::query()->sole();

    $attachment = partnerEmailsSent('subscription.invoice', $owner->email)->sole()->getAttachments()[0];
    expect($attachment->getFilename())->toBe($invoice->number.'.pdf')->and(str_starts_with($attachment->getBody(), '%PDF'))->toBeTrue();

    VendorSubscription::query()->sole()->forceFill(['auto_renew' => true, 'saved_payment_card_id' => null])->save();
    $subscriptions->autoCharge($invoice->refresh());

    expect(partnerEmailHtml(partnerEmailsSent('subscription.auto_charge_failed', $owner->email)->sole()))
        ->toContain($invoice->number)->toContain('The saved card for auto-renew was removed.');
});

it('tells the requesting store that its product request was rejected, with the reason', function () {
    [$owner, $vendor] = sellerAccount();
    $requests = app(ProductRequests::class);
    $request = $requests->submit('vendor', $vendor->id, null, $owner->id, ['title_en' => 'Bose QuietComfort Ultra', 'title_ar' => 'بوز كوايت كومفورت']);

    $requests->reject($request, adminAccount()->id, 'Already listed as a variant');

    expect(partnerEmailHtml(partnerEmailsSent('seller.product_request_decision', $owner->email)->sole()))
        ->toContain('Bose QuietComfort Ultra')->toContain('Already listed as a variant');
});

it('emails a delivery agent whose document was rejected, with a delivery panel link', function () {
    $user = User::factory()->create();
    $agent = DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => 'Rashid', 'status' => 'active']);
    $type = DocumentType::query()->create(['country_id' => $this->country->id, 'code' => 'driving_licence', 'name_en' => 'Driving licence', 'name_ar' => 'رخصة القيادة', 'business_type' => DocumentOwner::DeliveryAgent, 'requirement' => 'mandatory']);
    $document = Document::query()->create(['document_type_id' => $type->id, 'owner_type' => 'agent', 'owner_id' => $agent->id, 'file_path' => 'documents/x.pdf', 'original_name' => 'licence.pdf', 'mime' => 'application/pdf', 'size' => 100, 'status' => DocumentStatus::Pending]);

    app(Documents::class)->reject($document, adminAccount()->id, 'The photo is blurry');

    expect(partnerEmailHtml(partnerEmailsSent('document.rejected', $user->email)->sole()))
        ->toContain('Driving licence')->toContain('The photo is blurry')->toContain('delivery.');
});

it('emails the marketer when a withdrawal is paid', function () {
    [$user, $marketer] = marketerAccount();
    $withdrawal = MarketerWithdrawal::query()->create(['number' => 'WD-UAE-000001', 'marketer_profile_id' => $marketer->id, 'currency' => 'AED', 'amount' => '120.00', 'status' => WithdrawalStatus::Approved]);

    app(StateMachine::class)->move($withdrawal, WithdrawalStatus::Processed, ActorType::Admin, 1, null, ['processed_at' => now()]);

    expect(partnerEmailHtml(partnerEmailsSent('marketer.withdrawal_paid', $user->email)->sole()))->toContain('WD-UAE-000001')->toContain('marketer.');
});

it('emails the delivery agent the payslip with its PDF', function () {
    $user = User::factory()->create();
    $agent = DeliveryAgent::query()->create(['user_id' => $user->id, 'name' => 'Rashid', 'status' => 'active']);
    app(SettingsService::class)->set('delivery.pay_per_stop', '6.00', $this->country);
    $subOrder = subOrderFor(Vendor::factory()->create(), SubOrderStatus::Packed);
    $subOrder->order->forceFill(['customer_user_id' => User::factory()->create()->id])->save();
    $assignment = app(DeliveryDispatch::class)->assign($subOrder->refresh(), $agent, ActorType::Admin, 1);
    $at = now()->subWeeks(2);
    $assignment->forceFill(['status' => AssignmentStatus::Delivered, 'delivered_at' => $at, 'updated_at' => $at])->saveQuietly();

    expect(app(AgentPay::class)->issueDue($this->country))->toBe(1);
    $payslip = DeliveryPayslip::query()->sole();

    $attachment = partnerEmailsSent('delivery.payslip_issued', $user->email)->sole()->getAttachments()[0];
    expect($attachment->getFilename())->toBe('payslip-'.$payslip->number.'.pdf')->and(str_starts_with($attachment->getBody(), '%PDF'))->toBeTrue();
});

it('alerts only opted-in admins who may view applications about a new partner application', function () {
    $reviewer = adminAccount('uae', 'vendor_relations');
    $silent = adminAccount('uae', 'vendor_relations');
    $support = adminAccount('uae', 'support');
    $preferences = app(EmailPreferences::class);
    $preferences->set($reviewer, EmailPreferences::topic('admin.partner_application'), true);
    $preferences->set($support, EmailPreferences::topic('admin.partner_application'), true);
    $application = partnerEmailsApplication(['status' => ApplicationStatus::Draft]);

    app(StateMachine::class)->move($application, ApplicationStatus::Submitted, ActorType::Applicant);

    expect(partnerEmailHtml(partnerEmailsSent('admin.partner_application', $reviewer->email)->sole()))->toContain($application->number)->toContain('SoundMax')
        ->and(partnerEmailsSent('admin.partner_application', $silent->email))->toBeEmpty()
        ->and(partnerEmailsSent('admin.partner_application', $support->email))->toBeEmpty();
});

it('alerts admins about a failed job once per job class per hour, never about email jobs', function () {
    $admin = adminAccount();
    app(EmailPreferences::class)->set($admin, EmailPreferences::topic('admin.job_failed'), true);
    $job = fn (string $name): SyncJob => new SyncJob(app(), json_encode(['displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]), 'sync', 'default');

    event(new JobFailed('sync', $job('App\\Jobs\\NightlyReport'), new RuntimeException('Disk full')));
    event(new JobFailed('sync', $job('App\\Jobs\\NightlyReport'), new RuntimeException('Disk full again')));
    event(new JobFailed('sync', $job(EventEmail::class), new RuntimeException('SMTP down')));

    expect(partnerEmailHtml(partnerEmailsSent('admin.job_failed', $admin->email)->sole()))->toContain('App\\Jobs\\NightlyReport')->toContain('Disk full')
        ->and(EmailLog::query()->where('event', 'admin.job_failed')->count())->toBe(1);

    $this->travel(1)->hours();
    event(new JobFailed('sync', $job('App\\Jobs\\NightlyReport'), new RuntimeException('Disk full')));
    expect(EmailLog::query()->where('event', 'admin.job_failed')->count())->toBe(2);
});
