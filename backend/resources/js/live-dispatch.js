/**
 * D16 / O1: live dispatch boards (admin Delivery → Dispatch, carrier Live
 * dispatch). The board element carries `data-live-dispatch="{country code}"`;
 * when Reverb is turned on (VITE_REVERB_ENABLED=true) it joins the private channel `country.{code}.ops`
 * and shows how many dispatch changes arrived since the page loaded, with a
 * reload button. Without Reverb (or when the socket cannot connect) the
 * notice keeps saying the board refreshes on reload.
 */
export async function initLiveDispatch() {
    const board = document.querySelector('[data-live-dispatch]');

    if (!board || !import.meta.env.VITE_REVERB_APP_KEY || import.meta.env.VITE_REVERB_ENABLED !== 'true') {
        return;
    }

    const { createEcho } = await import('./echo');
    const echo = createEcho();

    if (!echo) {
        return;
    }

    const status = board.querySelector('[data-live-status]');
    const action = board.querySelector('[data-live-reload]');
    let changes = 0;

    const say = (text) => {
        if (status && text) {
            status.textContent = text;
        }
    };

    echo.private(`country.${board.dataset.liveDispatch}.ops`)
        .subscribed(() => say(board.dataset.liveConnected))
        .error(() => say(board.dataset.liveOffline))
        .listen('.delivery.dispatch', () => {
            changes += 1;
            say((board.dataset.liveChanges ?? '').replace(':count', String(changes)));
            action?.classList.remove('hidden');
        });

    action?.addEventListener('click', () => window.location.reload());
}
