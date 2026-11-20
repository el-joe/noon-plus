/**
 * Panel sidebar — docs/design/panel-nav-design.md §5, docs/project/panel-navigation.md.
 *
 * - QC-025 mobile drawer (< 1024 px): scrim, focus trap, Esc / scrim / close button, body scroll lock.
 * - Accordion groups (`[data-nav-group]`, open = `data-open`): only the group of the current page is open on
 *   load and opening a group closes the other one (open state is not remembered).
 * - Search filter with highlighted matches and a result line (matching groups open while searching).
 * - Arrow-key navigation; `/` focuses the search.
 * - Desktop icon rail (`data-nav-rail` on <html>, the only state remembered in localStorage) with flyouts
 *   for groups and tooltips for top-level links.
 */
const DESKTOP = '(min-width: 1024px)';
const RAIL_KEY = 'nawy.nav.rail';

const remember = (key, value) => {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the rail just is not remembered.
    }
};

/** Lower-case, without Arabic diacritics / tatweel and with unified alef, yeh and teh marbuta forms. */
const normalise = (text) =>
    (text || '')
        .toLocaleLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ًͯ-ٰٟـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .trim();

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function initDrawer() {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-panel-sidebar]');
    const scrim = document.querySelector('[data-sidebar-scrim]');
    const close = sidebar?.querySelector('[data-sidebar-close]');

    if (!toggle || !sidebar) {
        return;
    }

    const isOpen = () => sidebar.hasAttribute('data-drawer-open');

    const setOpen = (open) => {
        sidebar.toggleAttribute('data-drawer-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';

        if (scrim) {
            if (open) {
                scrim.hidden = false;
                requestAnimationFrame(() => scrim.setAttribute('data-visible', ''));
            } else {
                scrim.removeAttribute('data-visible');
                window.setTimeout(() => !isOpen() && (scrim.hidden = true), 220);
            }
        }

        if (open) {
            (sidebar.querySelector('[data-nav-search]') ?? sidebar.querySelector('[aria-current="page"]') ?? sidebar.querySelector(FOCUSABLE))?.focus();
        } else {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => setOpen(!isOpen()));
    close?.addEventListener('click', () => setOpen(false));
    scrim?.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (!isOpen() || window.matchMedia(DESKTOP).matches) {
            return;
        }

        if (event.key === 'Escape' && !event.defaultPrevented) {
            setOpen(false);
        } else if (event.key === 'Tab') {
            const focusables = [...sidebar.querySelectorAll(FOCUSABLE)].filter((element) => element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden');
            const first = focusables[0];
            const last = focusables[focusables.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
    });

    window.matchMedia(DESKTOP).addEventListener('change', (event) => event.matches && isOpen() && setOpen(false));
}

function initNavigation() {
    const nav = document.querySelector('[data-nav]');

    if (!nav) {
        return;
    }

    const root = document.documentElement;
    const scroller = nav.querySelector('.nav-scroll');
    const groups = [...nav.querySelectorAll('[data-nav-group]')];
    const entries = [...nav.querySelectorAll('[data-nav-entry]')];
    const dividers = [...nav.querySelectorAll('[data-nav-divider]')];
    const search = nav.querySelector('[data-nav-search]');
    const kbd = nav.querySelector('[data-nav-search-kbd]');
    const results = nav.querySelector('[data-nav-results]');
    const railToggle = document.querySelector('[data-nav-rail-toggle]');
    const isRtl = () => getComputedStyle(nav).direction === 'rtl';
    const isRail = () => root.hasAttribute('data-nav-rail') && window.matchMedia(DESKTOP).matches;
    const button = (group) => group.querySelector('[data-nav-toggle]');
    const isOpen = (group) => group.hasAttribute('data-open');

    // ---- Groups (accordion) ------------------------------------------------------------------
    const setOpen = (group, open) => {
        group.toggleAttribute('data-open', open);

        if (!isRail()) {
            button(group).setAttribute('aria-expanded', String(open));
        }
    };

    /** Opening a group closes the others. */
    const openOnly = (target) => groups.forEach((group) => setOpen(group, group === target));

    /** Initial state: only the group of the current page is open. */
    const reset = () => groups.forEach((group) => setOpen(group, group.hasAttribute('data-nav-active')));

    // ---- Rail flyouts and tooltips -----------------------------------------------------------
    let popped = null;
    let openTimer = null;
    let closeTimer = null;

    const closePopover = () => {
        window.clearTimeout(openTimer);
        window.clearTimeout(closeTimer);

        if (!popped) {
            return;
        }

        popped.classList.remove('is-flyout', 'is-tooltip');

        if (popped.hasAttribute('data-nav-group')) {
            button(popped).setAttribute('aria-expanded', 'false');
        }

        popped = null;
    };

    const openPopover = (entry) => {
        window.clearTimeout(openTimer);
        window.clearTimeout(closeTimer);

        if (popped === entry) {
            return;
        }

        closePopover();
        const anchor = entry.querySelector('[data-nav-toggle], [data-nav-link]');
        const rect = anchor.getBoundingClientRect();

        if (entry.hasAttribute('data-nav-group')) {
            // Top aligned 6 px above the rail item, clamped to the viewport.
            entry.classList.add('is-flyout');
            button(entry).setAttribute('aria-expanded', 'true');
            const panel = entry.querySelector('[data-nav-panel]');
            const top = Math.min(rect.top - 6, window.innerHeight - 8 - panel.offsetHeight);
            entry.style.setProperty('--nav-flyout-top', `${Math.max(8, top)}px`);
        } else {
            // Tooltip vertically centred on the item.
            entry.classList.add('is-tooltip');
            entry.style.setProperty('--nav-flyout-top', `${rect.top + rect.height / 2}px`);
        }

        popped = entry;
    };

    const openLater = (entry) => {
        window.clearTimeout(openTimer);
        window.clearTimeout(closeTimer);
        openTimer = window.setTimeout(() => openPopover(entry), entry.hasAttribute('data-nav-group') ? 150 : 300);
    };

    const closeLater = () => {
        window.clearTimeout(openTimer);
        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(closePopover, popped?.classList.contains('is-tooltip') ? 0 : 150);
    };

    entries.forEach((entry) => {
        entry.addEventListener('mouseenter', () => isRail() && (popped === entry ? window.clearTimeout(closeTimer) : openLater(entry)));
        entry.addEventListener('mouseleave', () => isRail() && closeLater());
        entry.addEventListener('focusin', (event) => {
            // Keyboard focus on a top-level link shows its tooltip; a group's flyout opens on Enter / →.
            if (isRail() && entry.dataset.navType === 'link' && event.target.matches(':focus-visible')) {
                openPopover(entry);
            }
        });
        entry.addEventListener('focusout', (event) => {
            if (isRail() && !entry.contains(event.relatedTarget)) {
                closeLater();
            }
        });
    });

    document.addEventListener('pointerdown', (event) => {
        if (popped && !popped.contains(event.target)) {
            closePopover();
        }
    });

    const syncRail = () => {
        const rail = root.hasAttribute('data-nav-rail');
        const label = rail ? railToggle?.dataset.labelExpand : railToggle?.dataset.labelCollapse;
        railToggle?.setAttribute('aria-pressed', String(rail));
        railToggle?.setAttribute('aria-label', label ?? '');
        railToggle?.setAttribute('title', label ?? '');
        groups.forEach((group) => button(group).setAttribute('aria-expanded', String(isRail() ? group === popped : isOpen(group))));
    };

    railToggle?.addEventListener('click', () => {
        closePopover();
        root.toggleAttribute('data-nav-rail');
        remember(RAIL_KEY, root.hasAttribute('data-nav-rail') ? '1' : '0');
        syncRail();
        markTruncated();
    });

    window.matchMedia(DESKTOP).addEventListener('change', () => {
        closePopover();
        syncRail();
    });

    const openFlyoutFromKeyboard = (group) => {
        openPopover(group);
        group.querySelector('[data-nav-link]')?.focus();
    };

    groups.forEach((group) => {
        button(group).addEventListener('click', (event) => {
            if (isRail()) {
                if (popped === group) {
                    closePopover();
                } else if (event.detail === 0) {
                    openFlyoutFromKeyboard(group);
                } else {
                    openPopover(group);
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

    // ---- Search -----------------------------------------------------------------------------
    const labels = [...nav.querySelectorAll('[data-nav-label]')];
    labels.forEach((label) => (label.dataset.navOriginal = label.textContent));

    /** Renders the label with the matched substring highlighted (case-insensitive). */
    const highlight = (label, query) => {
        const text = label.dataset.navOriginal;
        const index = query ? text.toLocaleLowerCase().indexOf(query) : -1;

        if (index === -1) {
            label.textContent = text;

            return;
        }

        const mark = document.createElement('mark');
        mark.className = 'nav-mark';
        mark.textContent = text.slice(index, index + query.length);
        label.replaceChildren(text.slice(0, index), mark, text.slice(index + query.length));
    };

    const plural = (count, one, many) => (count === 1 ? one : many).replace(':count', String(count));

    const filter = (raw) => {
        const query = normalise(raw);
        const literal = (raw || '').trim().toLocaleLowerCase();
        labels.forEach((label) => highlight(label, literal));

        if (kbd) {
            kbd.textContent = raw ? 'Esc' : '/';
        }

        if (query === '') {
            nav.removeAttribute('data-searching');
            entries.forEach((entry) => (entry.hidden = false));
            dividers.forEach((divider) => (divider.hidden = false));
            nav.querySelectorAll('[data-nav-item]').forEach((item) => (item.hidden = false));
            results?.classList.add('hidden');
            reset();

            return;
        }

        nav.setAttribute('data-searching', '');
        let matches = 0;
        let matchedGroups = 0;

        entries.forEach((entry) => {
            const entryMatches = normalise(entry.dataset.navText).includes(query);

            if (entry.dataset.navType === 'link') {
                entry.hidden = !entryMatches;
                matches += entryMatches ? 1 : 0;

                return;
            }

            let visible = 0;
            entry.querySelectorAll('[data-nav-item]').forEach((item) => {
                const show = entryMatches || normalise(item.dataset.navText).includes(query);
                item.hidden = !show;
                visible += show ? 1 : 0;
            });
            entry.hidden = visible === 0;
            setOpen(entry, visible > 0);
            matches += visible;
            matchedGroups += visible > 0 ? 1 : 0;
        });

        dividers.forEach((divider) => (divider.hidden = true));

        if (results) {
            const { template, resultOne, resultMany, groupOne, groupMany, empty } = results.dataset;
            results.textContent = matches === 0 ? empty : template.replace(':results', plural(matches, resultOne, resultMany)).replace(':groups', plural(matchedGroups, groupOne, groupMany));
            results.classList.remove('hidden');
        }
    };

    search?.addEventListener('input', () => filter(search.value));
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && search.value !== '') {
            event.preventDefault();
            search.value = '';
            filter('');
        } else if (event.key === 'Enter') {
            const first = visibleRows().find((element) => element.matches('[data-nav-link]'));

            if (first && search.value.trim() !== '') {
                event.preventDefault();
                first.click();
            }
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            visibleRows()[0]?.focus();
        }
    });

    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const typing = target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));

        if (event.key === '/' && search && !typing && !event.ctrlKey && !event.metaKey && !event.altKey && search.getClientRects().length > 0) {
            event.preventDefault();
            search.focus();
        }

        if (event.key === 'Escape' && popped) {
            event.preventDefault();
            const anchor = popped.querySelector('[data-nav-toggle], [data-nav-link]');
            closePopover();
            anchor?.focus();
        }
    });

    // ---- Keyboard: arrows move across visible rows -------------------------------------------
    const visibleRows = () =>
        [...nav.querySelectorAll('[data-nav-toggle], [data-nav-link]')].filter((element) => {
            if (element.closest('[hidden]')) {
                return false;
            }

            const panel = element.closest('[data-nav-panel]');

            if (!panel) {
                return true;
            }

            const group = panel.closest('[data-nav-group]');

            return isRail() ? group === popped : isOpen(group);
        });

    nav.addEventListener('keydown', (event) => {
        const current = event.target.closest('[data-nav-toggle], [data-nav-link]');

        if (!current) {
            return;
        }

        const rows = visibleRows();
        const index = rows.indexOf(current);
        const forward = isRtl() ? 'ArrowLeft' : 'ArrowRight';
        const backward = isRtl() ? 'ArrowRight' : 'ArrowLeft';
        const group = current.closest('[data-nav-group]');
        let next = null;

        if (event.key === 'ArrowDown') {
            next = rows[index + 1] ?? rows[0];
        } else if (event.key === 'ArrowUp') {
            next = index <= 0 && search && !isRail() ? search : (rows[index - 1] ?? rows[rows.length - 1]);
        } else if (event.key === 'Home') {
            next = rows[0];
        } else if (event.key === 'End') {
            next = rows[rows.length - 1];
        } else if (event.key === forward && current.matches('[data-nav-toggle]')) {
            if (isRail()) {
                openFlyoutFromKeyboard(group);
            } else if (!isOpen(group)) {
                openOnly(group);
            } else {
                next = group.querySelector('[data-nav-link]');
            }
        } else if (event.key === backward && group) {
            if (isRail()) {
                next = button(group);
                closePopover();
            } else if (current.matches('[data-nav-link]')) {
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

    // ---- Full label as a title when it is cut off -------------------------------------------
    const markTruncated = () =>
        labels.forEach((label) => {
            if (label.scrollWidth > label.clientWidth + 1) {
                label.setAttribute('title', label.dataset.navOriginal);
            } else {
                label.removeAttribute('title');
            }
        });

    // ---- Initial state ------------------------------------------------------------------------
    reset();
    syncRail();
    requestAnimationFrame(markTruncated);

    const active = nav.querySelector('[aria-current="page"]');

    if (active && scroller && scroller.scrollHeight > scroller.clientHeight) {
        const offset = active.getBoundingClientRect().top - scroller.getBoundingClientRect().top;

        if (offset > scroller.clientHeight - 48) {
            scroller.scrollTop += offset - scroller.clientHeight / 2;
        }
    }
}

export function initPanelSidebar() {
    initDrawer();
    initNavigation();
}
