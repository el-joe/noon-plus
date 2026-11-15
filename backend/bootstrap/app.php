<?php

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Panels\Http\Middleware\EnsureTenantPermission;
use App\Domains\Panels\Support\Panel;
use App\Shared\Http\ApiResponse;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.can' => EnsureTenantPermission::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): string => route(
            (Panel::fromRequest($request)?->key ?? 'admin').'.login'
        ));
        $middleware->redirectUsersTo(fn (Request $request): string => route(
            (Panel::fromRequest($request)?->key ?? 'admin').'.dashboard'
        ));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Gateway credentials are write-only: never flash them back into the session.
        $exceptions->dontFlash(['credentials', 'credentials.*']);
        $exceptions->render(fn (TransitionRefused $refused, Request $request) => $request->is('api/*') || $request->expectsJson()
            ? ApiResponse::error('not_allowed', $refused->getMessage(), 422)
            : back()->withErrors(['action' => $refused->getMessage()]));
        /*
        | G-W11: Laravel's own 401/403/404/405/422/429 (and other HTTP errors)
        | on `api/*` use the API envelope {error: {code, message, fields}}.
        */
        $exceptions->render(fn (ValidationException $invalid, Request $request) => $request->is('api/*')
            ? ApiResponse::error('validation_failed', $invalid->getMessage(), $invalid->status, $invalid->errors())
            : null);
        $exceptions->render(fn (AuthenticationException $unauthenticated, Request $request) => $request->is('api/*')
            ? ApiResponse::error('unauthenticated', __('Sign in to continue.'), 401)
            : null);
        $exceptions->render(function (HttpExceptionInterface $http, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $http->getStatusCode();
            [$code, $message] = match ($status) {
                400 => ['bad_request', __('The request could not be understood.')],
                401 => ['unauthenticated', __('Sign in to continue.')],
                403 => ['forbidden', $http->getMessage() !== '' && $http->getMessage() !== 'This action is unauthorized.' ? $http->getMessage() : __('You are not allowed to do this.')],
                404 => ['not_found', __('Not found.')],
                405 => ['method_not_allowed', __('This method is not allowed here.')],
                409 => ['conflict', $http->getMessage() !== '' ? $http->getMessage() : __('This conflicts with the current state.')],
                419 => ['session_expired', __('The page expired; try again.')],
                429 => ['too_many_requests', __('Too many attempts. Try again in a moment.')],
                503 => ['service_unavailable', __('Nawy is briefly unavailable. Try again soon.')],
                default => ['http_error', $http->getMessage() !== '' ? $http->getMessage() : __('Something went wrong.')],
            };

            return ApiResponse::error($code, $message, $status)->withHeaders($http->getHeaders());
        });
        $exceptions->render(fn (CartRejected $rejected, Request $request) => $request->is('api/*')
            ? ApiResponse::error($rejected->errorCode, $rejected->getMessage(), $rejected->status)
            : null);
        /*
        | QA-008: an unexpected error on `api/*` is a plain 500 envelope —
        | never a stack trace, whatever APP_DEBUG says (it is still logged).
        */
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*') || $error instanceof HttpExceptionInterface || $error instanceof ValidationException || $error instanceof AuthenticationException || $error instanceof HttpResponseException) {
                return null;
            }

            return ApiResponse::error('server_error', __('Something went wrong on our side. Try again in a moment.'), 500);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withCommands([
        __DIR__.'/../app/Domains/Countries/Console',
        __DIR__.'/../app/Domains/Identity/Console',
        __DIR__.'/../app/Domains/Cart/Console',
        __DIR__.'/../app/Domains/FlashSales/Console',
        __DIR__.'/../app/Domains/Orders/Console',
        __DIR__.'/../app/Domains/Payments/Console',
        __DIR__.'/../app/Domains/Finance/Console',
        __DIR__.'/../app/Domains/Warranty/Console',
        __DIR__.'/../app/Domains/GiftCards/Console',
        __DIR__.'/../app/Domains/Membership/Console',
        __DIR__.'/../app/Domains/Contracts/Console',
        __DIR__.'/../app/Domains/Campaigns/Console',
        __DIR__.'/../app/Domains/Marketers/Console',
        __DIR__.'/../app/Domains/Documents/Console',
        __DIR__.'/../app/Domains/Classifieds/Console',
        __DIR__.'/../app/Domains/RealEstate/Console',
        __DIR__.'/../app/Domains/Travel/Console',
        __DIR__.'/../app/Domains/Advertising/Console',
        __DIR__.'/../app/Domains/Delivery/Console',
        __DIR__.'/../app/Domains/LiveMedia/Console',
        __DIR__.'/../app/Domains/Content/Console',
    ])
    ->create();
