import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * D16: the Reverb client for the panels. Only connects when the build env
 * turns it on (VITE_REVERB_ENABLED=true and VITE_REVERB_APP_KEY); otherwise
 * the pages stay on reload and no socket is opened (no console errors where
 * no Reverb server runs).
 * Private channels are authorised by the session route `/broadcasting/auth`.
 *
 * @returns {Echo|null}
 */
export function createEcho() {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key || import.meta.env.VITE_REVERB_ENABLED !== 'true') {
        return null;
    }

    if (window.Echo) {
        return window.Echo;
    }

    window.Pusher = Pusher;
    // An https page cannot open ws:// (mixed content): use wss on 443 there, so one
    // build serves both the http and https local vhosts (deploy/local).
    const pageIsHttps = window.location.protocol === 'https:';
    const scheme = pageIsHttps ? 'https' : (import.meta.env.VITE_REVERB_SCHEME ?? 'https');
    const builtPort = pageIsHttps && import.meta.env.VITE_REVERB_SCHEME === 'http' ? 443 : import.meta.env.VITE_REVERB_PORT;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(builtPort ?? 80),
        wssPort: Number(builtPort ?? 443),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': csrf } },
    });

    return window.Echo;
}
