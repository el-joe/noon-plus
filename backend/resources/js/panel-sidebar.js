/**
 * QC-025: the panel sidebar opens as a drawer below 1024px.
 */
export function initPanelSidebar() {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-panel-sidebar]');

    if (!toggle || !sidebar) {
        return;
    }

    const setOpen = (open) => {
        sidebar.classList.toggle('hidden', !open);
        sidebar.classList.toggle('flex', open);
        toggle.setAttribute('aria-expanded', String(open));
    };

    toggle.addEventListener('click', () => setOpen(sidebar.classList.contains('hidden')));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && window.innerWidth < 1024) {
            setOpen(false);
        }
    });
}
