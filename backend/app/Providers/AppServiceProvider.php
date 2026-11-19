<?php

namespace App\Providers;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Listeners\NotifyCustomerOfStateChange;
use App\Domains\Finance\Support\ExchangeRates\ExchangeRateProvider;
use App\Domains\Finance\Support\ExchangeRates\LogExchangeRateProvider;
use App\Domains\Finance\Support\ExchangeRates\ManualExchangeRateProvider;
use App\Domains\Finance\Support\ExchangeRates\OpenExchangeRatesProvider;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Identity\Support\AdminRoleLabels;
use App\Domains\Identity\Support\TokenScopes;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Policies\ListingPolicy;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Support\FakeLiveMediaProvider;
use App\Domains\LiveMedia\Support\LiveKitProvider;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Policies\SubOrderPolicy;
use App\Domains\Panels\Listeners\NotifySellerOfStateChange;
use App\Domains\Panels\Support\SystemHealth;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;
use App\Shared\Http\Middleware\ThrottlePerRoute;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Push\ArrayPushSender;
use App\Shared\Notifications\Push\FcmPushSender;
use App\Shared\Notifications\Push\LogPushSender;
use App\Shared\Notifications\Push\PushSender;
use App\Shared\Notifications\Sms\ArraySmsSender;
use App\Shared\Notifications\Sms\LogSmsSender;
use App\Shared\Notifications\Sms\SmsSender;
use App\Shared\StateMachine\StateMoved;
use App\Shared\Support\UserNames;
use App\Shared\Validation\FriendlyValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CountryContext::class);
        // QA-015: `throttle:N,1` counts per route and market.
        $this->app->bind(ThrottleRequests::class, ThrottlePerRoute::class);
        $this->app->scoped(VendorWorkspace::class);
        $this->app->scoped(MarketerWorkspace::class);
        $this->app->scoped(UserNames::class);
        $this->app->scoped(AdminRoleLabels::class);
        $this->app->singleton(CountryClock::class);
        $this->app->singleton(MoneyFactory::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(LiveMediaProvider::class, fn (): LiveMediaProvider => config('nawy.streams.driver') === 'livekit'
            ? new LiveKitProvider(config('services.livekit.url'), config('services.livekit.api_key'), config('services.livekit.api_secret'), config('services.livekit.ws_url'))
            : new FakeLiveMediaProvider);
        // Push notifications: `log` (development), `array` (tests), `fcm` (needs the owner's Firebase project + service account).
        $this->app->singleton(PushSender::class, fn (): PushSender => match (config('nawy.push.driver')) {
            'array' => new ArrayPushSender,
            'fcm' => FcmPushSender::fromConfig(),
            default => new LogPushSender,
        });
        // Legacy phone-code first sign-in: `log` (development) or `array` (tests); a live provider needs the owner's SMS account.
        $this->app->singleton(SmsSender::class, fn (): SmsSender => match (config('nawy.sms.driver')) {
            'array' => new ArraySmsSender,
            'log' => new LogSmsSender,
            default => throw new \InvalidArgumentException('Unknown SMS driver '.config('nawy.sms.driver').': set NAWY_SMS_DRIVER to log, or configure a provider.'),
        });
        $this->app->singleton(ExchangeRateProvider::class, fn (): ExchangeRateProvider => match (config('nawy.exchange_rates.driver')) {
            'log' => new LogExchangeRateProvider,
            'openexchangerates' => new OpenExchangeRatesProvider(config('nawy.exchange_rates.app_id')),
            default => new ManualExchangeRateProvider,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->refuseTestDriversInProduction();
        Validator::resolver(fn ($translator, array $data, array $rules, array $messages, array $attributes) => new FriendlyValidator($translator, $data, $rules, $messages, $attributes));
        Event::listen(StateMoved::class, NotifyCustomerOfStateChange::class);
        Event::listen(StateMoved::class, NotifySellerOfStateChange::class);
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn (ScheduledTaskFinished|ScheduledTaskFailed $event) => app(SystemHealth::class)->recordScheduledRun($event));

        Gate::policy(Listing::class, ListingPolicy::class);
        Gate::policy(SubOrder::class, SubOrderPolicy::class);

        foreach (AdminPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(AdminAccess::class)->allows($user, $permission));
        }

        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && app(TokenScopes::class)->accepts($token, app()->bound('request') ? request() : null),
        );

        $this->rateLimiters();
    }

    /**
     * WP11 security review: the fake payment and social sign-in drivers accept
     * unsigned input (any "paid" webhook, any email). They exist for tests and
     * local runs only — production refuses to boot with them.
     */
    private function refuseTestDriversInProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        foreach (['nawy.payments.driver' => 'NAWY_PAYMENTS_DRIVER', 'nawy.customer_auth.social_driver' => 'NAWY_SOCIAL_AUTH_DRIVER'] as $key => $env) {
            if (config($key) === 'fake') {
                throw new RuntimeException("{$env}=fake is for tests and local runs only; set the live driver in production.");
            }
        }
    }

    /**
     * Named limiters (QA-015): sign-ins per email + IP, coupon codes and
     * anonymous holds (QA-010, QA-014) per user or IP. Unnamed `throttle:N,1`
     * counters are kept per route and market by
     * {@see ThrottlePerRoute}.
     */
    private function rateLimiters(): void
    {
        foreach (['panel-login', 'ops-login', 'delivery-login'] as $name) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perMinute(6)->by($name.':'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(30)->by($name.':ip:'.$request->ip()),
                // WP11: per account whatever the IP, so rotating addresses cannot brute-force one password.
                Limit::perHour(30)->by($name.':account:'.Str::lower((string) $request->input('email'))),
            ]);
        }

        /*
        | WP11: the default limit of every API route (`throttleApi`), per signed-in user or IP.
        | Only a `local` app spares loopback callers: there the storefront's server render and
        | the Playwright run all arrive from 127.0.0.1 (docs/project/test-report.md). Every
        | other environment, and every other address, keeps the limit.
        */
        RateLimiter::for('api', fn (Request $request): Limit => app()->environment('local') && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            ? Limit::none()
            : Limit::perMinute((int) config('nawy.api_rate_per_minute', 600))
                ->by('api:'.($request->user('sanctum')?->id ?? 'ip:'.$request->ip())));

        RateLimiter::for('coupon-apply', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('coupon:'.($request->user('sanctum')?->id ?? 'ip:'.$request->ip()).'|'.$request->route('market')));

        RateLimiter::for('cart-writes', fn (Request $request): Limit => $request->user('sanctum') !== null
            ? Limit::perMinute(120)->by('cart:user:'.$request->user('sanctum')->id)
            : Limit::perMinute((int) config('nawy.checkout.guest_cart_writes_per_minute', 30))->by('cart:ip:'.$request->ip()));

        RateLimiter::for('travel-bookings', function (Request $request): array {
            $limits = [Limit::perMinute(5)->by('travel-bookings:'.($request->user('sanctum')?->id ?? 'ip:'.$request->ip()))];

            if ($request->user('sanctum') === null && ! $request->boolean('submit', true)) {
                $limits[] = Limit::perHour((int) config('nawy.travel.guest_holds_per_hour', 3))->by('travel-holds:ip:'.$request->ip());
            }

            return $limits;
        });

        // D9: customer password sign-in, forgot/reset, change, and social sign-in.
        RateLimiter::for('password-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('password-login:ip:'.$request->ip()),
            Limit::perHour(30)->by('password-login:account:'.Str::lower((string) $request->input('email'))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(3)->by('password-reset:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(10)->by('password-reset:ip:'.$request->ip()),
        ]);
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute(5)->by('password-change:'.($request->user('sanctum')?->id ?? 'ip:'.$request->ip())));
        RateLimiter::for('social-auth', fn (Request $request): Limit => Limit::perMinute(20)->by('social-auth:ip:'.$request->ip()));
        // Submitting a new password is limited apart from asking for the link, so one reset flow never trips its own limit.
        RateLimiter::for('password-reset-submit', fn (Request $request): array => [
            Limit::perMinute(6)->by('password-reset-submit:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(20)->by('password-reset-submit:ip:'.$request->ip()),
        ]);
        RateLimiter::for('panel-password-reset', fn (Request $request): array => [
            Limit::perMinute(3)->by('panel-password-reset:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(10)->by('panel-password-reset:ip:'.$request->ip()),
        ]);

        RateLimiter::for('phone-code', fn (Request $request): array => [
            Limit::perMinute(5)->by('phone-code:ip:'.$request->ip()),
            Limit::perMinute(3)->by('phone-code:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
            Limit::perDay(10)->by('phone-code:day:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
        ]);
        RateLimiter::for('email-code', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('email:'.mb_strtolower((string) $request->input('email'))),
        ]);
    }
}
