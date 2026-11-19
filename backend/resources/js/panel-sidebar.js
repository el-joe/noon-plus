/**
 * Panel sidebar (docs/project/panel-navigation.md).
 *
 * - QC-025: below 1024px the sidebar opens as a drawer from the topbar menu button.
 * - Accordion groups (`[data-nav-group]`, open = `data-open`): only the group of the current page is open on
 *   load and opening a group closes the other one (open state is not remembered).
 * - Search filter (matching groups open while searching), arrow-key navigation.
 * - Desktop icon rail (`data-nav-rail` on <html>, remembered in localStorage) with flyouts for groups and
 *   tooltips for links.
 */
const DESKTOP = '(min-width: 1024px)';
const RAIL_KEY = 'nawy.nav.rail';

const storage = {
    write(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // Private mode or blocked storage: the state just is not remembered.
        }
    },
};

/** Lower-case, without Arabic diacritics and with unified alef / yeh / teh marbuta forms. */
const normalise = (text) =>
    (text || '')
        .toLocaleLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ًͯ-ٰٟـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .trim();

function initDrawer() {
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
        if (event.key === 'Escape' && !window.matchMedia(DESKTOP).matches && !sidebar.classList.contains('hidden')) {
            setOpen(false);
            toggle.focus();
        }
    });
}

function initNavigation() {
    const nav = document.querySelector('[data-nav]');

    if (!nav) {
        return;
    }

    const root = document.documentElement;
    const sidebar = nav.closest('[data-panel-sidebar]');
    const groups = [...nav.querySelectorAll('[data-nav-group]')];
    const entries = [...nav.querySelectorAll('[data-nav-entry]')];
    const search = nav.querySelector('[data-nav-search]');
    const empty = nav.querySelector('[data-nav-empty]');
    const railToggle = document.querySelector('[data-nav-rail-toggle]');
    const isRtl = () => getComputedStyle(nav).direction === 'rtl';
    const isRail = () => root.hasAttribute('data-nav-rail') && window.matchMedia(DESKTOP).matches;
    const button = (group) => group.querySelector('[data-nav-toggle]');
    const isOpen = (group) => group.hasAttribute('data-open');

    // ---- Groups -------------------------------------------------------------------------------
    const setOpen = (group, open) => {
        group.toggleAttribute('data-open', open);

        if (!isRail()) {
            button(group).setAttribute('aria-expanded', String(open));
        }
    };

    /** Accordion: opening a group closes the others. */
    const openOnly = (target) => groups.forEach((group) => setOpen(group, group === target));

    /** Back to the initial state: only the group of the current page is open. */
    const reset = () => groups.forEach((group) => setOpen(group, group.hasAttribute('data-nav-active')));

    // ---- Rail flyouts -------------------------------------------------------------------------
    let flyout = null;
    let closeTimer = null;

    const closeFlyout = () => {
        window.clearTimeout(closeTimer);

        if (!flyout) {
            return;
        }

        flyout.classList.remove('is-flyout');

        if (flyout.hasAttribute('data-nav-group')) {
            button(flyout).setAttribute('aria-expanded', 'false');
        }

        flyout = null;
    };

    const openFlyout = (entry) => {
        window.clearTimeout(closeTimer);

        if (flyout === entry) {
            return;
        }

        closeFlyout();
        const anchor = entry.querySelector('[data-nav-toggle], [data-nav-link]');
        const rect = anchor.getBoundingClientRect();
        entry.classList.add('is-flyout');
        entry.style.setProperty('--nav-flyout-top', `${rect.top}px`);

        if (entry.hasAttribute('data-nav-group')) {
            button(entry).setAttribute('aria-expanded', 'true');
            const panel = entry.querySelector('[data-nav-panel]');
            const overflow = rect.top + panel.offsetHeight - (window.innerHeight - 8);

            if (overflow > 0) {
                entry.style.setProperty('--nav-flyout-top', `${Math.max(8, rect.top - overflow)}px`);
            }
        }

        flyout = entry;
    };

    const scheduleClose = () => {
        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(closeFlyout, 150);
    };

    entries.forEach((entry) => {
        entry.addEventListener('mouseenter', () => isRail() && openFlyout(entry));
        entry.addEventListener('mouseleave', () => isRail() && scheduleClose());
        entry.addEventListener('focusin', () => isRail() && openFlyout(entry));
        entry.addEventListener('focusout', (event) => {
            if (isRail() && !entry.contains(event.relatedTarget)) {
                scheduleClose();
            }
        });
    });

    const setRail = (rail) => {
        closeFlyout();
        root.toggleAttribute('data-nav-rail', rail);
        storage.write(RAIL_KEY, rail ? '1' : '0');
        syncRailToggle();
    };

    const syncRailToggle = () => {
        const rail = root.hasAttribute('data-nav-rail');
        const label = rail ? railToggle?.dataset.labelExpand : railToggle?.dataset.labelCollapse;
        railToggle?.setAttribute('aria-pressed', String(rail));
        railToggle?.setAttribute('aria-label', label ?? '');
        railToggle?.setAttribute('title', label ?? '');
        groups.forEach((group) => button(group).setAttribute('aria-expanded', String(rail ? group === flyout : isOpen(group))));
    };

    railToggle?.addEventListener('click', () => setRail(!root.hasAttribute('data-nav-rail')));
    window.matchMedia(DESKTOP).addEventListener('change', () => {
        closeFlyout();
        syncRailToggle();
    });

    groups.forEach((group) => {
        button(group).addEventListener('click', () => {
            if (isRail()) {
                if (flyout === group) {
                    closeFlyout();
                } else {
                    openFlyout(group);
                    group.querySelector('[data-nav-link]')?.focus();
                }

                return;
            }

            if (isOpen(group)) {
                setOpen(group, false);
            } else {
                openOnly(group);
            }
        });
    });

    // ---- Search -------------------------------------------------------------------------------
    const filter = (raw) => {
        const query = normalise(raw);

        if (query === '') {
            nav.removeAttribute('data-searching');
            entries.forEach((entry) => entry.removeAttribute('hidden'));
            nav.querySelectorAll('[data-nav-item]').forEach((item) => item.removeAttribute('hidden'));
            empty?.classList.add('hidden');
            reset();

            return;
        }

        nav.setAttribute('data-searching', '');

        let matches = 0;

        entries.forEach((entry) => {
            const entryMatches = normalise(entry.dataset.navText).includes(query);

            if (entry.dataset.navType === 'link') {
                entry.toggleAttribute('hidden', !entryMatches);
                matches += entryMatches ? 1 : 0;

                return;
            }

            let visible = 0;
            entry.querySelectorAll('[data-nav-item]').forEach((item) => {
                const show = entryMatches || normalise(item.dataset.navText).includes(query);
                item.toggleAttribute('hidden', !show);
                visible += show ? 1 : 0;
            });
            entry.toggleAttribute('hidden', visible === 0);
            setOpen(entry, visible > 0);
            matches += visible;
        });

        empty?.classList.toggle('hidden', matches > 0);
    };

    search?.addEventListener('input', () => filter(search.value));
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && search.value !== '') {
            event.preventDefault();
            event.stopPropagation();
            search.value = '';
            filter('');
        } else if (event.key === 'Enter') {
            const first = visibleFocusables().find((element) => element.matches('[data-nav-link]'));

            if (first && search.value.trim() !== '') {
                event.preventDefault();
                first.click();
            }
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            visibleFocusables()[0]?.focus();
        }
    });

    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const typing = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));

        if (event.key === '/' && search && !typing && !event.ctrlKey && !event.metaKey && !event.altKey && search.offsetParent !== null) {
            event.preventDefault();
            search.focus();
        }

        if (event.key === 'Escape' && flyout) {
            const anchor = flyout.querySelector('[data-nav-toggle], [data-nav-link]');
            closeFlyout();
            anchor?.focus();
        }
    });

    // ---- Keyboard: arrows move between visible headers and links ----------------------------
    const visibleFocusables = () =>
        [...nav.querySelectorAll('[data-nav-toggle], [data-nav-link]')].filter((element) => {
            if (element.closest('[hidden]')) {
                return false;
            }

            const panel = element.closest('[data-nav-panel]');

            if (panel) {
                const group = panel.closest('[data-nav-group]');

                return isRail() ? group === flyout : isOpen(group);
            }

            return true;
        });

    nav.addEventListener('keydown', (event) => {
        const current = event.target.closest('[data-nav-toggle], [data-nav-link]');

        if (!current) {
            return;
        }

        const items = visibleFocusables();
        const index = items.indexOf(current);
        const forward = isRtl() ? 'ArrowLeft' : 'ArrowRight';
        const backward = isRtl() ? 'ArrowRight' : 'ArrowLeft';
        const group = current.closest('[data-nav-group]');
        let next = null;

        if (event.key === 'ArrowDown') {
            next = items[index + 1] ?? items[0];
        } else if (event.key === 'ArrowUp') {
            next = index <= 0 && search && !isRail() ? search : (items[index - 1] ?? items[items.length - 1]);
        } else if (event.key === 'Home') {
            next = items[0];
        } else if (event.key === 'End') {
            next = items[items.length - 1];
        } else if (event.key === forward && current.matches('[data-nav-toggle]') && !isRail()) {
            if (!isOpen(group)) {
                openOnly(group);
            } else {
                next = group.querySelector('[data-nav-link]');
            }
        } else if (event.key === backward && group && !isRail()) {
            if (current.matches('[data-nav-link]')) {
                next = button(group);
            } else if (isOpen(group)) {
                setOpen(group, false);
            }
        } else {
            return;
        }

        event.preventDefault();
        next?.focus();
    });

    // ---- Initial state ------------------------------------------------------------------------
    reset();
    syncRailToggle();

    const active = nav.querySelector('[aria-current="page"]');

    if (active && sidebar && sidebar.scrollHeight > sidebar.clientHeight) {
        const offset = active.getBoundingClientRect().top - sidebar.getBoundingClientRect().top;

        if (offset > sidebar.clientHeight - 80) {
            sidebar.scrollTop += offset - sidebar.clientHeight / 2;
        }
    }
}

export function initPanelSidebar() {
    initDrawer();
    initNavigation();
}
