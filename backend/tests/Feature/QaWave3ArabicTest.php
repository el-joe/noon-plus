<?php

use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Identity\Notifications\CustomerPasswordResetNotification;
use App\Models\User;
use App\Shared\Localization\Label;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| QC-028: Arabic backend language files and the request / recipient locale.
*/

it('has an Arabic translation for every translatable string and enum label, with placeholders kept', function () {
    $arabic = json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];

    foreach ([app_path(), resource_path('views'), base_path('routes')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/(?:__|trans_choice|@lang)\\(\\s*('((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/s", $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = ($match[3] ?? '') !== '' ? stripcslashes($match[3]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[2]);

                if ($key !== '' && ! preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) && ! array_key_exists($key, $arabic)) {
                    $missing[] = $file->getRelativePathname().': '.$key;
                }
            }
        }
    }

    foreach (File::allFiles(app_path()) as $file) {
        if (! str_contains($file->getPathname(), '/Enums/') || ! preg_match('/namespace ([^;]+);.*enum (\w+)\s*:\s*string/s', $file->getContents(), $enum)) {
            continue;
        }

        foreach (($enum[1].'\\'.$enum[2])::cases() as $case) {
            if (! array_key_exists($label = Label::key($case), $arabic)) {
                $missing[] = 'enum '.$enum[2].': '.$label;
            }
        }
    }

    $brokenPlaceholders = collect($arabic)->filter(function (string $value, string $key): bool {
        preg_match_all('/:[a-zA-Z_]+/', $key, $expected);

        return collect($expected[0])->contains(fn (string $placeholder): bool => ! str_contains($value, $placeholder));
    })->keys()->all();

    expect($missing)->toBe([])->and($brokenPlaceholders)->toBe([])
        ->and(trans('validation.required', ['attribute' => 'x'], 'ar'))->not->toBe(trans('validation.required', ['attribute' => 'x'], 'en'));
});

describe('locale per request', function () {
    beforeEach(function () {
        $this->seed(CountrySeeder::class);
        $this->withoutVite();
    });

    it('answers the API in Arabic for -ar markets and for Accept-Language: ar', function () {
        $this->postJson('/api/v1/uae-ar/auth/email-code/send', [])
            ->assertUnprocessable()->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('error.fields.email.0', 'حقل البريد الإلكتروني مطلوب.');
        $this->postJson('/api/v1/uae-en/auth/email-code/send', [], ['Accept-Language' => 'ar'])
            ->assertJsonPath('error.fields.email.0', 'The email field is required.');
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'ar-AE,ar;q=0.9'])
            ->assertNotFound()->assertHeader('Content-Language', 'ar')->assertJsonPath('error.message', __('Not found.', locale: 'ar'));
    });

    it('renders panels in the signed-in person\'s language, right-to-left in Arabic, and saves the switch', function () {
        $admin = User::factory()->create(['preferred_locale' => 'en']);
        $admin->memberships()->create(['role' => MembershipRole::Admin]);
        $url = fn (string $path = ''): string => 'http://admin.'.config('nawy.base_domain').'/'.$path;

        $this->actingAs($admin)->get($url())->assertOk()->assertSee('dir="ltr"', false)->assertSee('data-locale-switcher', false);
        $this->from($url())->post($url('locale'), ['locale' => 'ar'])->assertRedirect($url());

        expect($admin->refresh()->preferred_locale)->toBe('ar');
        $this->get($url())->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('Sign out', locale: 'ar'));

        auth()->logout();
        $this->post($url('locale'), ['locale' => 'ar']);
        $this->get($url('login'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');
    });

    it('mails in the recipient\'s language, not the request\'s', function () {
        $customer = User::createPasswordless('sara@example.com', 'Sara', verified: true);
        $customer->forceFill(['preferred_locale' => 'ar'])->save();

        $this->postJson('/api/v1/uae-en/auth/password/forgot', ['email' => 'sara@example.com'])->assertStatus(202);

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($message) => $message->getOriginalMessage());
        expect($sent)->toHaveCount(1)
            ->and($sent->first()->getSubject())->toBe(__('Reset your Nawy password', locale: 'ar'))
            ->and($sent->first()->getHtmlBody())->toContain('dir="rtl"')
            ->and(Str::contains($sent->first()->getHtmlBody(), 'Reset your Nawy password'))->toBeFalse();
        expect(CustomerPasswordResetNotification::class)->toBeString();
    });
});
