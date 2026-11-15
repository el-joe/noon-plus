<?php

namespace App\Providers;

use App\Domains\Countries\Support\CountryClock;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Customers\Listeners\NotifyCustomerOfStateChange;
use App\Domains\Identity\Enums\AdminPermission;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Identity\Support\TokenScopes;
use App\Domains\Listings\Models\Listing;
use App\Domains\Listings\Policies\ListingPolicy;
use App\Domains\LiveMedia\Contracts\LiveMediaProvider;
use App\Domains\LiveMedia\Support\FakeLiveMediaProvider;
use App\Domains\LiveMedia\Support\LiveKitProvider;
use App\Domains\Marketers\Support\MarketerWorkspace;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Orders\Policies\SubOrderPolicy;
use App\Domains\Panels\Support\SystemHealth;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Models\User;
use App\Shared\Http\Middleware\ThrottlePerRoute;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMoved;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        $this->app->singleton(CountryClock::class);
        $this->app->singleton(MoneyFactory::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(LiveMediaProvider::class, fn (): LiveMediaProvider => config('nawy.streams.driver') === 'livekit'
            ? new LiveKitProvider(config('services.livekit.url'), config('services.livekit.api_key'), config('services.livekit.api_secret'), config('services.livekit.ws_url'))
            : new FakeLiveMediaProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(StateMoved::class, NotifyCustomerOfStateChange::class);
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
            ]);
        }

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

        RateLimiter::for('email-code', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('email:'.mb_strtolower((string) $request->input('email'))),
        ]);
    }
}
