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
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': csrf } },
    });

    return window.Echo;
}
