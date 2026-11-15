import { initCountryDatetimeFields } from './country-datetime';

document.addEventListener('DOMContentLoaded', () => {
    initCountryDatetimeFields();
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

// Realtime client (./echo.js) is not bundled yet: its npm packages
// (laravel-echo, pusher-js) are not installed and adding packages needs the
// owner's approval. Re-enable with `import './echo';` once they are.
