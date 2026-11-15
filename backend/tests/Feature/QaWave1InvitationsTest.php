<?php

use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Onboarding\Notifications\ApplicationEmailCodeNotification;
use App\Domains\Onboarding\Notifications\PartnerWelcomeNotification;
use App\Domains\Onboarding\Support\ApplicationReview;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Models\User;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function wave1ApplicationCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(ApplicationEmailCodeNotification::class, function (ApplicationEmailCodeNotification $notification, array $channels, $notifiable) use ($email, &$code): bool {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $code = $notification->code;
        }

        return true;
    });

    return (string) $code;
}

/**
 * A complete vendor application for `$email` (not submitted).
 *
 * @return array{0: string, 1: array<string, string>}
 */
function wave1VendorApplication(string $email, string $password = 'attacker-pass-1'): array
{
    $type = DocumentType::query()->create(['country_id' => test()->country->id, 'code' => 'trade_licence', 'name_en' => 'Trade licence', 'name_ar' => 'رخصة', 'business_type' => 'vendor', 'requirement' => 'mandatory']);
    $response = test()->postJson('/api/v1/uae-en/partner-applications', ['type' => 'vendor', 'name' => 'Applicant', 'email' => $email, 'phone' => '+971501234567', 'password' => $password, 'password_confirmation' => $password])
        ->assertCreated()->assertJsonPath('data.email_verified', false);
    $number = $response->json('data.number');
    $headers = ['X-Application-Token' => $response->json('meta.access_token')];
    test()->postJson("/api/v1/uae-en/partner-applications/{$number}/steps/store", ['store_name' => 'Shop', 'slug' => 'shop-'.uniqid(), 'business_type' => 'llc', 'legal_name' => 'Shop LLC', 'cr_number' => 'CR-1', 'fulfilment' => 'fbm'], $headers)->assertOk();
    test()->postJson("/api/v1/uae-en/partner-applications/{$number}/steps/address", ['city' => 'Dubai', 'street' => 'Street 1'], $headers)->assertOk();
    test()->post("/api/v1/uae-en/partner-applications/{$number}/documents", ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('l.pdf', 20, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertCreated();

    return [$number, $headers];
}

it('QA-002: an application cannot be submitted or approved before the email is proven with the mailed code', function () {
    [$number, $headers] = wave1VendorApplication('victim@example.com');
    $submit = fn () => test()->postJson("/api/v1/uae-en/partner-applications/{$number}/submit", ['accept' => true, 'full_name' => 'Applicant'], $headers);

    $submit()->assertUnprocessable()->assertJsonValidationErrors(['email_code'], 'error.fields');
    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/email/verify", ['code' => '000000'], $headers)->assertUnprocessable();

    $application = PartnerApplication::query()->where('number', $number)->sole();
    expect(fn () => app(ApplicationReview::class)->approve($application, 1))->toThrow(TransitionRefused::class);

    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/email/verify", ['code' => wave1ApplicationCode('victim@example.com')], $headers)
        ->assertOk()->assertJsonPath('data.email_verified', true);
    $submit()->assertOk();
});

it('QA-002: approval adds the store to an existing account only with the owner code, keeps their password and marks the email verified', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com', 'password' => 'own-password-1', 'email_verified_at' => null]);
    [$number, $headers] = wave1VendorApplication('owner@example.com', 'wizard-pass-1');
    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/email/verify", ['code' => wave1ApplicationCode('owner@example.com')], $headers)->assertOk();
    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/submit", ['accept' => true, 'full_name' => 'Owner'], $headers)->assertOk();

    app(ApplicationReview::class)->approve(PartnerApplication::query()->where('number', $number)->sole(), adminAccount()->id);

    $owner->refresh();
    expect(Hash::check('own-password-1', $owner->password))->toBeTrue()
        ->and(Hash::check('wizard-pass-1', $owner->password))->toBeFalse()
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->hasMembership(MembershipRole::ProductVendor, $this->country))->toBeTrue();
    Notification::assertSentTo($owner, PartnerWelcomeNotification::class, fn (PartnerWelcomeNotification $mail): bool => $mail->existingAccount && $mail->passwordSetupUrl !== null);
});

it('QA-002: an email change asks for a new code', function () {
    [$number, $headers] = wave1VendorApplication('first@example.com');
    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/email/verify", ['code' => wave1ApplicationCode('first@example.com')], $headers)->assertOk();

    $this->postJson("/api/v1/uae-en/partner-applications/{$number}/steps/account", ['name' => 'Applicant', 'email' => 'second@example.com', 'phone' => '+971501234567'], $headers)
        ->assertOk()->assertJsonPath('data.email_verified', false);
    expect(wave1ApplicationCode('second@example.com'))->not->toBe('');
});

it('QA-003: adding a driver or supervisor with an existing email only sends an invitation, reveals no stored name and attaches nothing until accepted', function () {
    $owner = User::factory()->create();
    $carrier = Carrier::query()->create(['code' => 'fast', 'name' => 'Fast Couriers', 'status' => 'active', 'owner_user_id' => $owner->id]);
    $owner->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);
    $victim = User::factory()->create(['name' => 'Real Stored Name', 'email' => 'victim@example.com', 'password' => 'victim-pass-1']);

    $this->actingAs($owner)->post(onPanel('carrier', 'agents'), ['name' => 'Hijacked Driver', 'email' => 'victim@example.com'])
        ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => ! str_contains($status, 'Real Stored Name'));
    $this->actingAs($owner)->post(onPanel('carrier', 'supervisors'), ['name' => 'Whoever', 'email' => 'victim@example.com'])
        ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => ! str_contains($status, 'Real Stored Name'));

    expect(DeliveryAgent::query()->count())->toBe(0)
        ->and($victim->memberships()->count())->toBe(0);

    $urls = [];
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification) use (&$urls): bool {
        $urls[] = $notification->acceptUrl;

        return true;
    });
    expect($urls)->toHaveCount(2);

    auth()->logout();
    $this->get($urls[0])->assertOk()->assertSee('Fast Couriers')->assertDontSee('Real Stored Name');
    $this->post($urls[0])->assertRedirect(onPanel('carrier', 'login'));

    expect(DeliveryAgent::query()->where('user_id', $victim->id)->exists())->toBeTrue()
        ->and(Hash::check('victim-pass-1', $victim->refresh()->password))->toBeTrue();
    $this->get($urls[0])->assertNotFound();
});
