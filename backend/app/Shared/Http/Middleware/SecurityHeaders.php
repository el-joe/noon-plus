<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (WP11 security review): no MIME
 * sniffing, no framing of the panels (clickjacking), a strict referrer, HSTS
 * on HTTPS and a Content-Security-Policy for the Blade panels (Vite assets,
 * Google Fonts, inline styles/scripts the panels use, Reverb websockets; skipped while the Vite dev server runs).
 * API responses are JSON and get the non-CSP headers only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self)');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $request->is('api/*') && ! Vite::isRunningHot() && ! $headers->has('Content-Security-Policy') && str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            $devOrigins = $this->devMediaOrigins();
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                trim("img-src 'self' data: blob: https: ".$devOrigins),
                trim("media-src 'self' blob: https: ".$devOrigins),
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' data: https://fonts.gstatic.com",
                "script-src 'self' 'unsafe-inline'",
                "connect-src 'self' ws: wss: https:",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }

    /**
     * Outside production, uploads are served from the app's own http origin
     * (APP_URL/storage, e.g. http://localhost:8000) while the panels run on
     * other hosts (partner.nawy.test:8000), so `https:` alone breaks images
     * in dev. Production keeps the strict list.
     */
    private function devMediaOrigins(): string
    {
        if (app()->isProduction()) {
            return '';
        }

        return collect([config('app.url'), config('app.asset_url'), config('filesystems.disks.public.url')])
            ->filter(fn ($url): bool => is_string($url) && $url !== '')
            ->map(function (string $url): ?string {
                $parts = parse_url($url);

                if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
                    return null;
                }

                return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            })
            ->filter()->unique()->implode(' ');
    }
}
