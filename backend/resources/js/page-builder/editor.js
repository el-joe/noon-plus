import { buttonClass, clone, h, icon, placeholderThumb, requestJson, uid } from './dom';
import { defaults, missing, pickLocale, renderFields, translationGaps } from './fields';

/**
 * Admin page builder — full-screen editor (Figma "Admin · Page builder"
 * 247:208): top bar (status, undo / redo, history, preview, save, publish),
 * page outline (zones → sections, drag & drop and keyboard reordering, hide,
 * duplicate, delete), live storefront preview (device + language, show
 * hidden) and the settings panel generated from the block schemas, with the
 * EN | AR | Both language switch for bilingual content. Saves the draft as a
 * JSON revision (autosave 2 s after the last change, at most every 30 s),
 * shared by the EN and AR pages of the address.
 */
export function initPageBuilder(root) {
    const boot = JSON.parse(root.querySelector('#page-builder-state').textContent);
    const i18n = JSON.parse(root.querySelector('#page-builder-i18n').textContent);
    const t = (key, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value)), i18n[key] ?? key);
    const canEdit = Boolean(root.querySelector('[data-pb-save]'));

    const state = {
        pages: Object.fromEntries(Object.entries(boot.pages ?? {}).map(([locale, page]) => [locale, { title: page.title ?? '', seo_title: page.seo_title ?? '', seo_description: page.seo_description ?? '' }])),
        zones: boot.zones,
        selected: firstSelection(boot),
        panel: 'section',
        lang: 'both',
        dirty: false,
        saving: false,
        failed: false,
        savedAt: boot.savedAt,
        errors: {},
        collapsedZones: new Set(),
        filter: '',
        sinceLive: boot.sinceLive,
        revision: boot.revision ?? 0,
        conflict: null,
    };
    if (!state.selected) state.panel = 'page';
    if (window.location.hash === '#history') state.panel = 'history';
    let previewOn = (() => { try { return window.localStorage.getItem('nawy.pb.preview') === '1'; } catch { return false; } })();
    const openItems = new WeakMap();
    const openGroups = new Map();
    const labelCache = new Map();
    const undoStack = [];
    const redoStack = [];

    const $ = (selector) => root.querySelector(selector);
    const outline = $('[data-pb-outline]');
    const form = $('[data-pb-form]');
    const workspace = $('[data-pb-workspace]');
    const gallery = $('[data-pb-gallery]');

    // ---- helpers -------------------------------------------------------
    const typeLabel = (type) => boot.types[type]?.label ?? type;
    const zoneLabel = (zone) => boot.zoneLabels?.[zone] ?? zone;
    const thumbnail = (type, size = 'h-16 w-28') => {
        const box = h('div', { class: `${size} grid shrink-0 place-items-center overflow-hidden rounded-lg bg-subtle` });
        const url = boot.types[type]?.thumbnail;
        if (url) {
            const img = h('img', { src: url, alt: '', class: 'max-h-full max-w-full object-contain p-1', loading: 'lazy' });
            img.addEventListener('error', () => box.replaceChildren(placeholderThumb(typeLabel(type))));
            box.append(img);
        } else {
            box.append(placeholderThumb(typeLabel(type)));
        }
        return box;
    };
    const blockAt = (sel) => (sel && sel.zone ? state.zones[sel.zone]?.find((b) => b.id === sel.id) : null);
    const indexOf = (zone, id) => state.zones[zone].findIndex((b) => b.id === id);
    const errorFor = (path) => state.errors[path] ?? null;
    const countErrors = (prefix) => Object.keys(state.errors).filter((key) => key === prefix || key.startsWith(`${prefix}.`)).length;
    const pageLocale = boot.page.locale;
    const uiLocale = document.documentElement.lang?.startsWith('ar') ? 'ar' : 'en';
    const contentLocale = () => (state.lang === 'both' ? uiLocale : state.lang);
    const blockTitle = (block) => {
        const value = pickLocale(block.settings?.title ?? block.settings?.name ?? block.settings?.text, contentLocale());
        return typeof value === 'string' ? value.replace(/<[^>]+>/g, '') : '';
    };
    const blockIssues = (block) => (block.hidden ? [] : missing(boot.types[block.type]?.required, block.settings));
    const blockGaps = (block) => translationGaps(boot.types[block.type]?.fields ?? [], block.settings ?? {});

    async function picker(kind, { q = '', ids = [], type = null } = {}) {
        const url = new URL(boot.urls.picker.replace('__kind__', kind), window.location.origin);
        if (q) url.searchParams.set('q', q);
        if (type) url.searchParams.set('type', type);
        ids.forEach((id) => url.searchParams.append('ids[]', id));
        const result = await requestJson(url.toString());
        const rows = result.ok ? (result.data?.data ?? []) : [];
        remember(kind, rows);
        return rows;
    }
    function remember(kind, rows) {
        if (!labelCache.has(kind)) labelCache.set(kind, new Map());
        rows.forEach((row) => labelCache.get(kind).set(String(row.value), row));
    }
    async function labels(kind, values) {
        const cache = labelCache.get(kind) ?? new Map();
        const unknown = values.filter((v) => !cache.has(String(v)));
        if (unknown.length) await picker(kind, { ids: unknown });
        return labelCache.get(kind) ?? new Map();
    }
    const resolveLink = (link, locale = null) => {
        if (/^https?:\/\//i.test(link)) return link;
        const base = boot.storefront.replace(/-(en|ar)$/, `-${locale ?? pageLocale}`);
        return link.startsWith('#') ? `${base}${link}` : `${base}${link === '/' ? '' : link}`;
    };

    // ---- undo / redo / dirty / autosave --------------------------------
    const snapshot = () => JSON.stringify({ zones: state.zones, pages: state.pages });
    let lastSnapshot = snapshot();
    let snapshotTimer = null;
    function recordUndo() {
        clearTimeout(snapshotTimer);
        snapshotTimer = setTimeout(commitSnapshot, 400);
    }
    function commitSnapshot() {
        const now = snapshot();
        if (now === lastSnapshot) return;
        undoStack.push(lastSnapshot);
        if (undoStack.length > 100) undoStack.shift();
        redoStack.length = 0;
        lastSnapshot = now;
        paintUndo();
    }
    function restoreSnapshot(json) {
        const data = JSON.parse(json);
        state.zones = data.zones;
        state.pages = data.pages;
        lastSnapshot = json;
        if (state.selected && !blockAt(state.selected)) state.selected = null;
        state.dirty = true;
        paintAll();
    }
    function undo() {
        clearTimeout(snapshotTimer);
        commitSnapshot();
        if (!undoStack.length) return;
        redoStack.push(snapshot());
        restoreSnapshot(undoStack.pop());
    }
    function redo() {
        if (!redoStack.length) return;
        undoStack.push(snapshot());
        restoreSnapshot(redoStack.pop());
    }
    function paintUndo() {
        const undoButton = $('[data-pb-undo]');
        const redoButton = $('[data-pb-redo]');
        if (undoButton) undoButton.disabled = undoStack.length === 0 && !state.dirty;
        if (redoButton) redoButton.disabled = redoStack.length === 0;
    }

    let autosaveTimer = null;
    let lastAutosave = 0;
    function markDirty() {
        state.dirty = true;
        state.failed = false;
        recordUndo();
        paintSaveState();
        scheduleDraftPreview();
        if (!canEdit) return;
        clearTimeout(autosaveTimer);
        const wait = Math.max(2000, 30000 - (Date.now() - lastAutosave));
        autosaveTimer = setTimeout(() => { if (state.dirty && !state.saving && Object.keys(state.errors).length === 0) save({ auto: true }); }, wait);
    }

    const changedSections = () => Object.values(state.zones).flat().filter((block) => block.__touched).length;

    function paintSaveState() {
        const status = $('[data-pb-status]');
        if (status) {
            status.classList.toggle('text-fg-danger', state.failed);
            status.classList.toggle('cursor-pointer', state.failed);
            status.textContent = state.saving ? t('Saving…')
                : state.failed ? t('Couldn’t save — Retry')
                : state.dirty ? t('Unsaved changes')
                : state.savedAt ? `${t('Saved :time :country', { time: state.savedAt, country: boot.country.code.toUpperCase() })}${boot.savedBy ? ` · ${boot.savedBy}` : ''}` : '';
        }
        $('[data-pb-savebar]').hidden = !state.dirty;
        const count = changedSections();
        $('[data-pb-savebar-text]').textContent = count ? t('Unsaved changes · :n sections edited', { n: count }) : t('Unsaved changes');
        $('[data-pb-savebar-meta]').textContent = state.savedAt ? t('Saved :time (:country)', { time: state.savedAt, country: boot.country.code.toUpperCase() }) : '';
        root.querySelectorAll('[data-pb-save]').forEach((button) => { button.disabled = state.saving; });
        paintUndo();
    }

    const ctxBase = () => ({
        t,
        types: boot.types,
        childTypes: boot.childTypes,
        fieldGroups: boot.fieldGroups,
        country: boot.country,
        adminTimezone: boot.adminTimezone,
        currency: boot.country.currency,
        pageLocale,
        lang: state.lang,
        uiLocale,
        urls: boot.urls,
        openGroups,
        typeLabel,
        thumbnail,
        errorFor,
        countErrors,
        picker,
        labels,
        remember,
        resolveLink,
        clearErrors: (prefix) => { for (const key of Object.keys(state.errors)) if (key.startsWith(prefix)) delete state.errors[key]; },
        isOpen: (item) => openItems.get(item) ?? false,
        setOpen: (item, open) => openItems.set(item, open),
        changed: () => { const block = blockAt(state.selected); if (block) block.__touched = true; markDirty(); paintOutline(); paintProblems(); },
        rerenderForm: () => paintPanel(),
    });

    // ---- outline -------------------------------------------------------
    let dragged = null;
    const dropLine = h('li', { class: 'pointer-events-none mx-1 h-0.5 rounded bg-brand', 'aria-hidden': 'true' });

    function paintOutline() {
        const focused = document.activeElement?.closest?.('[data-pb-row]')?.dataset.pbRow;
        const scroll = outline.scrollTop;
        outline.replaceChildren(...boot.zoneOrder.map((zone) => zoneSection(zone)));
        outline.scrollTop = scroll;
        if (focused) outline.querySelector(`[data-pb-row="${CSS.escape(focused)}"] [data-pb-row-main]`)?.focus();
    }

    function zoneSection(zone) {
        const blocks = state.zones[zone] ?? [];
        const collapsed = state.collapsedZones.has(zone);
        const filter = state.filter.toLowerCase();
        const list = h('ol', { class: 'flex flex-col gap-0.5 rounded-lg', dataset: { pbZone: zone }, 'aria-label': t('Sections in :zone', { zone: zoneLabel(zone) }) });
        blocks.forEach((block, index) => {
            if (filter && !`${typeLabel(block.type)} ${blockTitle(block)}`.toLowerCase().includes(filter)) return;
            list.append(row(zone, block, index, blocks.length));
        });
        if (blocks.length === 0) {
            list.append(h('li', {}, h('button', { type: 'button', class: 'flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-line py-2 text-xs text-fg-secondary hover:border-line-focus hover:text-fg', onclick: () => openGallery(zone), disabled: !canEdit },
                icon('plus', 14), t('Add section to :zone', { zone: zoneLabel(zone) }))));
        }
        if (zone === 'listing') {
            list.append(h('li', { class: 'mt-1 flex items-start gap-2 rounded-lg bg-page px-3 py-2 text-xs text-fg-secondary' }, icon('lock', 14, 'mt-0.5'), t('Sponsored products and brand banners are inserted by rules.')));
        }
        list.addEventListener('dragover', (e) => {
            if (!dragged) return;
            if (!boot.allowed[zone].includes(dragged.type)) { list.classList.add('opacity-50'); list.title = t('Not allowed in :zone', { zone: zoneLabel(zone) }); return; }
            e.preventDefault();
            const target = e.target.closest('[data-pb-row]');
            if (target && target.parentElement === list) {
                const rect = target.getBoundingClientRect();
                if (e.clientY > rect.top + rect.height / 2) target.after(dropLine);
                else target.before(dropLine);
            } else if (!list.contains(dropLine)) {
                list.append(dropLine);
            }
        });
        list.addEventListener('dragleave', (e) => { if (!list.contains(e.relatedTarget)) { list.classList.remove('opacity-50'); list.title = ''; dropLine.remove(); } });
        list.addEventListener('drop', (e) => {
            list.classList.remove('opacity-50');
            if (!dragged || !boot.allowed[zone].includes(dragged.type)) return;
            e.preventDefault();
            const before = [...list.children].indexOf(dropLine);
            const rows = [...list.children].slice(0, Math.max(0, before)).filter((el) => el.dataset.pbRow);
            dropLine.remove();
            const to = rows.length ? indexOf(zone, rows[rows.length - 1].dataset.pbRow) + 1 : 0;
            if (dragged.fromGallery) addBlock(zone, dragged.type, to);
            else moveBlock(dragged.zone, dragged.id, zone, to);
        });
        return h('section', { class: 'flex flex-col gap-1 pt-2' },
            h('div', { class: 'flex h-8 items-center gap-1' },
                h('button', { type: 'button', class: 'flex flex-1 items-center gap-1.5 rounded px-1 text-xs font-medium uppercase tracking-wide text-fg-secondary hover:text-fg', 'aria-expanded': String(!collapsed), onclick: () => { if (collapsed) state.collapsedZones.delete(zone); else state.collapsedZones.add(zone); paintOutline(); } },
                    h('span', { class: `transition ${collapsed ? '-rotate-90 rtl:rotate-90' : ''}` }, icon('down', 14)), zoneLabel(zone),
                    h('span', { class: 'rounded-full bg-subtle px-1.5 font-normal normal-case' }, String(blocks.length))),
                canEdit ? h('button', { type: 'button', class: buttonClass.icon, title: t('Add section to :zone', { zone: zoneLabel(zone) }), 'aria-label': t('Add section to :zone', { zone: zoneLabel(zone) }), onclick: () => openGallery(zone) }, icon('plus', 16)) : null),
            collapsed ? null : list);
    }

    function row(zone, block, index, count) {
        const selected = state.selected?.id === block.id;
        const issues = blockIssues(block).length + countErrors(`zones.${zone}.${index}`);
        const gaps = block.hidden ? [] : blockGaps(block);
        const arGaps = gaps.filter((g) => g.locale === 'ar').length;
        const enGaps = gaps.filter((g) => g.locale === 'en').length;
        const scheduled = block.settings?.visibility?.starts_at || block.settings?.visibility?.ends_at;
        const title = blockTitle(block);
        const onlyLocale = Array.isArray(block.locales) && block.locales.length === 1 ? block.locales[0] : null;
        const item = h('li', {
            class: `group relative flex h-9 items-center gap-1 rounded-lg border px-1 ${issues ? 'border-line-danger' : selected ? 'border-line-brand bg-brand-subtle' : 'border-transparent hover:bg-page'} ${block.hidden ? 'text-fg-muted' : ''}`,
            draggable: canEdit ? 'true' : null, dataset: { pbRow: block.id },
        },
        h('span', { class: 'cursor-grab px-0.5 text-fg-muted', title: t('Drag to reorder') }, icon('grip', 14)),
        h('button', {
            type: 'button', class: 'flex h-full min-w-0 flex-1 items-center gap-2 rounded text-start text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus', dataset: { pbRowMain: '' },
            'aria-current': selected ? 'true' : null, 'aria-keyshortcuts': 'Alt+ArrowUp Alt+ArrowDown',
            onclick: () => select({ zone, id: block.id }),
            onkeydown: (e) => rowKeys(e, zone, block, index),
        },
        issues ? icon('alert-circle', 16, 'text-fg-danger') : icon('layers', 16, block.hidden ? 'text-fg-muted' : 'text-fg-secondary'),
        h('span', { class: 'truncate' }, typeLabel(block.type), title ? h('span', { class: block.hidden ? '' : 'text-fg-secondary' }, ` · ${title}`) : null)),
        onlyLocale ? h('span', { class: 'rounded bg-info-subtle px-1 text-[10px] font-semibold uppercase text-fg-info', title: t('Shown only in :language', { language: onlyLocale.toUpperCase() }) }, onlyLocale) : null,
        arGaps || enGaps ? h('span', { class: 'shrink-0 rounded bg-warning-subtle px-1 text-[10px] font-medium text-fg-warning', title: gaps.map((g) => `${g.locale.toUpperCase()}: ${g.label}`).join('\n') }, arGaps ? t('AR missing :n', { n: arGaps }) : t('EN missing :n', { n: enGaps })) : null,
        scheduled ? h('span', { class: 'text-fg-info', title: t('Scheduled') }, icon('clock', 14)) : null,
        block.__theirs ? h('span', { class: 'shrink-0 rounded bg-warning-subtle px-1 text-[10px] font-medium text-fg-warning' }, t('Changed by :name', { name: state.conflict?.by ?? t('Another admin') })) : null,
        issues ? h('span', { class: 'rounded-full bg-danger-subtle px-1.5 text-xs font-medium text-fg-danger' }, String(issues)) : null,
        canEdit ? h('span', { class: `items-center ${selected || block.hidden ? 'flex' : 'hidden group-hover:flex group-focus-within:flex'}` },
            h('button', { type: 'button', class: `${buttonClass.icon} size-7`, title: block.hidden ? t('Show') : t('Hide'), 'aria-pressed': String(Boolean(block.hidden)), onclick: () => toggleHidden(block) }, icon(block.hidden ? 'eye-off' : 'eye', 15)),
            selected || !block.hidden ? h('button', { type: 'button', class: `${buttonClass.icon} size-7`, title: t('Duplicate'), onclick: () => duplicateBlock(zone, block.id) }, icon('copy', 15)) : null,
            selected || !block.hidden ? rowMenu(zone, block, index, count) : null) : null);
        item.addEventListener('dragstart', (e) => {
            dragged = { zone, id: block.id, type: block.type };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', block.id);
            setTimeout(() => item.classList.add('opacity-40'), 0);
        });
        item.addEventListener('dragend', () => { dragged = null; dropLine.remove(); paintOutline(); });
        return item;
    }

    const menuItem = (glyph, label, action, disabled = false, danger = false) => h('button', { type: 'button', disabled, class: `flex items-center gap-2 rounded-lg px-2 py-1.5 text-start hover:bg-page disabled:opacity-40 ${danger ? 'text-fg-danger' : ''}`, onclick: (e) => { e.currentTarget.closest('details').open = false; action(); } }, icon(glyph, 15), label);

    function dropdown(trigger, items, width = 'w-52') {
        const menu = h('details', { class: 'relative' },
            h('summary', { class: `${trigger} cursor-pointer list-none [&::-webkit-details-marker]:hidden`, 'aria-label': t('More actions') }, icon('more', 15)),
            h('div', { class: `absolute end-0 z-30 mt-1 flex ${width} flex-col rounded-xl border border-line bg-surface p-1 text-sm shadow-xl` }, items));
        menu.addEventListener('toggle', () => { if (menu.open) setTimeout(() => document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.open = false; }, { once: true }), 0); });
        return menu;
    }

    function rowMenu(zone, block, index, count) {
        return dropdown(`${buttonClass.icon} size-7`, [
            menuItem('up', t('Move up'), () => moveBlock(zone, block.id, zone, index - 1), index === 0),
            menuItem('down', t('Move down'), () => moveBlock(zone, block.id, zone, index + 2), index === count - 1),
            ...boot.zoneOrder.filter((other) => other !== zone && boot.allowed[other].includes(block.type)).map((other) => menuItem('chevron', t('Move to :zone', { zone: zoneLabel(other) }), () => moveBlock(zone, block.id, other, state.zones[other].length))),
            menuItem('trash', t('Delete'), () => deleteBlock(zone, block.id), false, true),
        ]);
    }

    function rowKeys(e, zone, block, index) {
        const all = boot.zoneOrder.flatMap((z) => (state.zones[z] ?? []).map((b) => ({ zone: z, id: b.id })));
        const at = all.findIndex((entry) => entry.id === block.id);
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            moveBlock(zone, block.id, zone, index + (e.key === 'ArrowUp' ? -1 : 2));
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            const next = all[at + (e.key === 'ArrowUp' ? -1 : 1)];
            if (next) { select(next); outline.querySelector(`[data-pb-row="${CSS.escape(next.id)}"] [data-pb-row-main]`)?.focus(); }
        } else if ((e.key === 'Delete' || e.key === 'Backspace') && canEdit) {
            e.preventDefault();
            deleteBlock(zone, block.id);
        } else if (e.key.toLowerCase() === 'h' && !e.ctrlKey && !e.metaKey && canEdit) {
            e.preventDefault();
            toggleHidden(block);
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd' && canEdit) {
            e.preventDefault();
            duplicateBlock(zone, block.id);
        }
    }

    function toggleHidden(block) {
        block.hidden = !block.hidden;
        block.__touched = true;
        markDirty();
        paintOutline();
        paintPanel();
        paintProblems();
    }

    function moveBlock(fromZone, id, toZone, to) {
        const from = indexOf(fromZone, id);
        if (from < 0) return;
        const [block] = state.zones[fromZone].splice(from, 1);
        if (fromZone === toZone && to > from) to -= 1;
        to = Math.max(0, Math.min(to, state.zones[toZone].length));
        state.zones[toZone].splice(to, 0, block);
        block.__touched = true;
        state.errors = {};
        if (state.selected?.id === id) state.selected = { zone: toZone, id };
        markDirty();
        paintOutline();
        paintPanel();
        announce(t('Moved to position :n', { n: to + 1 }));
    }

    function duplicateBlock(zone, id) {
        const index = indexOf(zone, id);
        const copy = { ...clone(state.zones[zone][index]), id: uid(), raw: {}, __touched: true };
        state.zones[zone].splice(index + 1, 0, copy);
        state.errors = {};
        markDirty();
        select({ zone, id: copy.id });
    }

    /** Delete without a confirm: an "Undo" toast brings it back (design §6). */
    function deleteBlock(zone, id) {
        const index = indexOf(zone, id);
        if (index < 0) return;
        commitSnapshot();
        const [removed] = state.zones[zone].splice(index, 1);
        state.errors = {};
        markDirty();
        if (state.selected?.id === id) {
            const next = state.zones[zone][Math.min(index, state.zones[zone].length - 1)];
            state.selected = next ? { zone, id: next.id } : null;
        }
        paintOutline();
        paintPanel();
        paintProblems();
        toast(t(':type deleted', { type: typeLabel(removed.type) }), 'neutral', { label: t('Undo'), action: () => undo() });
    }

    function select(selection, { fromPreview = false } = {}) {
        state.selected = selection;
        state.panel = 'section';
        paintOutline();
        paintPanel();
        if (!fromPreview) postToPreview({ type: 'pb:select', id: selection?.id ?? null, label: selection ? typeLabel(blockAt(selection)?.type) : '', scroll: true });
        form.querySelector('[data-pb-panel-body]')?.scrollTo({ top: 0 });
    }

    // ---- right panel ---------------------------------------------------
    function openPanel(mode) {
        state.panel = state.panel === mode ? 'section' : mode;
        paintPanel();
    }

    function paintPanel() {
        const block = blockAt(state.selected);
        let mode = state.panel === 'section' && !block ? null : state.panel;
        // Without the preview the panel is the main column: never leave it empty.
        if (mode === null && !previewOn) mode = 'page';
        workspace.toggleAttribute('data-panel-closed', mode === null);
        $('[data-pb-panel-page]').hidden = mode !== 'page';
        $('[data-pb-panel-history]').hidden = mode !== 'history';
        form.hidden = mode !== 'section';
        root.querySelectorAll('[data-pb-open-panel]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.pbOpenPanel === mode)));
        requestAnimationFrame(fitFrame);
        if (mode !== 'section') return;
        const scroll = form.querySelector('[data-pb-panel-body]')?.scrollTop ?? 0;
        form.replaceChildren(...sectionPanel(block));
        const fresh = form.querySelector('[data-pb-panel-body]');
        if (fresh) fresh.scrollTop = scroll;
    }

    const KIND_LABELS = { editor: 'Editor block', ad: 'Ad', system: 'System', listing: 'Listing', header: 'Header', container: 'Container' };

    function sectionPanel(block) {
        const zone = state.selected.zone;
        const index = indexOf(zone, block.id);
        const type = boot.types[block.type] ?? { label: block.type, description: '', fields: [], required: [], kind: 'editor' };
        block.settings = block.settings && typeof block.settings === 'object' && !Array.isArray(block.settings) ? block.settings : {};
        const issues = blockIssues(block);
        const gaps = blockGaps(block);
        const ctx = { ...ctxBase(), blockKey: block.id };
        const path = `zones.${zone}.${index}.settings`;
        const errorMessages = Object.entries(state.errors).filter(([key]) => key.startsWith(`zones.${zone}.${index}.`)).map(([, message]) => message);
        const requiredLabels = issues.map((requirement) => requirement.split('|').map((key) => type.fields.find((f) => f.key === key)?.label ?? key).join(` ${t('or')} `));

        const header = h('div', { class: 'flex items-start gap-1 border-b border-line px-4 py-4' },
            h('div', { class: 'min-w-0 flex-1' },
                h('div', { class: 'flex flex-wrap items-center gap-2' }, h('h2', { class: 'truncate text-lg font-semibold' }, type.label), h('span', { class: 'rounded-md bg-subtle px-1.5 text-xs text-fg-secondary' }, t(KIND_LABELS[type.kind] ?? 'Editor block'))),
                h('p', { class: 'text-xs text-fg-secondary' }, t(':zone zone · section :n of :count', { zone: zoneLabel(zone), n: index + 1, count: state.zones[zone].length }))),
            canEdit ? dropdown(buttonClass.icon, [
                menuItem('up', t('Move up'), () => moveBlock(zone, block.id, zone, index - 1), index === 0),
                menuItem('down', t('Move down'), () => moveBlock(zone, block.id, zone, index + 2), index === state.zones[zone].length - 1),
                menuItem(block.hidden ? 'eye' : 'eye-off', block.hidden ? t('Show') : t('Hide'), () => toggleHidden(block)),
            ], 'w-56') : null,
            h('button', { type: 'button', class: buttonClass.icon, 'aria-label': t('Close'), onclick: () => { state.selected = null; paintOutline(); paintPanel(); } }, icon('x', 18)));

        const langSwitch = h('div', { class: 'flex items-center justify-between gap-2 border-b border-line px-4 py-2' },
            h('span', { class: 'text-xs text-fg-secondary' }, t('Content language')),
            h('div', { class: 'flex rounded-lg bg-page p-0.5', role: 'radiogroup', 'aria-label': t('Content language') }, [['en', 'EN'], ['ar', 'AR'], ['both', t('Both')]].map(([value, label]) => h('button', {
                type: 'button', role: 'radio', 'aria-checked': String(state.lang === value),
                class: `rounded-md px-3 py-1 text-xs ${state.lang === value ? 'bg-surface font-semibold shadow-xs ring-1 ring-line' : 'text-fg-secondary hover:text-fg'}`,
                onclick: () => { state.lang = value; paintPanel(); paintOutline(); },
            }, label))));

        const reference = h('div', { class: 'flex flex-col gap-2 rounded-xl border border-line p-2' },
            h('div', { class: 'grid aspect-[16/7] place-items-center overflow-hidden rounded-lg bg-subtle' }, (() => {
                if (!type.thumbnail) return placeholderThumb(t('Reference image coming from the design'), 'text-sm');
                const img = h('img', { src: type.thumbnail, alt: t('Reference image of :type', { type: type.label }), class: 'max-h-full max-w-full object-contain' });
                img.addEventListener('error', () => img.replaceWith(placeholderThumb(type.label, 'text-sm')));
                return img;
            })()),
            h('div', { class: 'flex items-start justify-between gap-3 px-1 pb-1' },
                h('p', { class: 'text-xs text-fg-secondary' }, type.description),
                canEdit ? changeTypeControl(zone, block) : null));

        const attention = errorMessages.length || requiredLabels.length ? h('div', { class: 'rounded-xl bg-danger-subtle px-4 py-3', role: 'alert' },
            h('p', { class: 'text-[15px] font-semibold text-fg-danger' }, t(':n fields need attention', { n: errorMessages.length + requiredLabels.length })),
            h('ul', { class: 'mt-1 flex flex-col gap-0.5 text-xs text-fg-danger' }, [...requiredLabels.map((label) => t(':field is required to publish.', { field: label })), ...errorMessages].map((message) => h('li', {}, `• ${message}`)))) : null;
        const translations = gaps.length ? h('div', { class: 'rounded-xl bg-warning-subtle px-4 py-3 text-xs text-fg-warning', role: 'status' },
            h('p', { class: 'text-sm font-semibold' }, t(':n translations missing', { n: gaps.length })),
            h('ul', { class: 'mt-1 flex flex-col gap-0.5' }, gaps.slice(0, 6).map((gap) => h('li', {}, `• ${gap.locale === 'ar' ? t('Arabic') : t('English')}: ${gap.label}`)))) : null;

        const visibility = h('div', { class: 'flex flex-col gap-5' },
            h('div', { class: 'flex items-start justify-between gap-4' },
                h('div', { class: 'flex flex-col' }, h('span', { class: 'text-sm font-medium' }, t('Visible on the page')), h('span', { class: 'text-xs text-fg-secondary' }, t('Hidden sections stay in the draft but are not shown.'))),
                switchFor(!block.hidden, (on) => { block.hidden = !on; block.__touched = true; markDirty(); paintOutline(); paintProblems(); })),
            h('div', { class: 'flex flex-col gap-1.5' },
                h('span', { class: 'text-sm font-medium' }, t('Show in')),
                h('div', { class: 'grid grid-cols-3 gap-1 rounded-lg bg-page p-1' }, [[[], t('Both languages')], [['en'], t('English only')], [['ar'], t('Arabic only')]].map(([locales, label]) => {
                    const active = JSON.stringify(block.locales?.length === 1 ? block.locales : []) === JSON.stringify(locales);
                    return h('button', { type: 'button', class: `rounded-md py-1.5 text-sm ${active ? 'bg-surface font-medium shadow-xs ring-1 ring-line' : 'text-fg-secondary hover:text-fg'}`, onclick: () => { block.locales = locales; block.__touched = true; markDirty(); paintOutline(); paintPanel(); } }, label);
                }))));

        const extraGroups = { visibility };
        if (boot.superAdmin) extraGroups.advanced = rawSettings(block);
        const summaries = {
            visibility: block.hidden ? t('Hidden') : block.settings?.visibility?.starts_at || block.settings?.visibility?.ends_at ? t('Scheduled') : t('Live · :languages', { languages: block.locales?.length === 1 ? block.locales[0].toUpperCase() : 'EN + AR' }),
        };

        const onlyLocale = Array.isArray(block.locales) && block.locales.length === 1 ? block.locales[0] : null;
        const languageNote = onlyLocale ? h('div', { class: 'flex flex-wrap items-center gap-3 rounded-xl border border-line bg-info-subtle px-4 py-3', role: 'status' },
            icon('globe', 18, 'text-fg-info'),
            h('p', { class: 'min-w-0 flex-1 text-sm' }, onlyLocale === 'ar' ? t('This section is shown only on the Arabic page. The English page does not show it.') : t('This section is shown only on the English page. The Arabic page does not show it.')),
            canEdit ? h('button', { type: 'button', class: buttonClass.outline, onclick: () => { block.locales = []; block.__touched = true; markDirty(); paintOutline(); paintPanel(); } }, t('Show in both languages')) : null) : null;

        const body = h('div', { class: 'flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pt-4', dataset: { pbPanelBody: '' } },
            languageNote, reference, attention, translations,
            renderFields(type.fields, block.settings, path, ctx, { grouped: true, extraGroups, summaries }));
        const footer = canEdit ? h('div', { class: 'flex h-14 shrink-0 items-center justify-between border-t border-line px-4' },
            h('button', { type: 'button', class: buttonClass.ghost, onclick: () => duplicateBlock(zone, block.id) }, icon('copy', 16), t('Duplicate')),
            h('button', { type: 'button', class: `${buttonClass.ghost} text-fg-danger`, onclick: () => deleteBlock(zone, block.id) }, icon('trash', 16), t('Delete section'))) : null;
        return [header, langSwitch, body, footer].filter(Boolean);
    }

    function switchFor(checked, onChange) {
        const id = `pb-switch-${uid()}`;
        return h('label', { class: 'relative mt-0.5 inline-flex shrink-0 cursor-pointer' },
            h('input', { id, type: 'checkbox', role: 'switch', class: 'peer sr-only', checked, onchange: (e) => onChange(e.target.checked) }),
            h('span', { class: 'h-6 w-10 rounded-full bg-line-strong transition peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-line-focus' }),
            h('span', { class: 'pointer-events-none absolute start-0.5 top-0.5 size-5 rounded-full bg-surface shadow transition peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4' }));
    }

    /** "Change type": compatible types (same group, allowed in the zone); matching fields are kept. */
    function changeTypeControl(zone, block) {
        const group = boot.types[block.type]?.group;
        const options = Object.entries(boot.types).filter(([type, def]) => type !== block.type && def.group === group && boot.allowed[zone].includes(type));
        if (!options.length) return null;
        const select = h('select', { class: 'absolute inset-0 cursor-pointer opacity-0', 'aria-label': t('Change type'), onchange: (e) => {
            const next = e.target.value;
            if (!next) return;
            const keys = new Set((boot.types[next]?.fields ?? []).map((f) => f.key));
            block.settings = { ...defaults(boot.types[next].fields), ...Object.fromEntries(Object.entries(block.settings).filter(([key]) => keys.has(key))) };
            block.type = next;
            block.__touched = true;
            markDirty();
            paintOutline();
            paintPanel();
        } }, h('option', { value: '' }, t('Change type')), options.map(([type, def]) => h('option', { value: type }, def.label)));
        return h('span', { class: 'relative shrink-0 text-xs font-medium text-fg-link hover:underline' }, t('Change type'), select);
    }

    function rawSettings(block) {
        const text = JSON.stringify(block.raw ?? {}, null, 2);
        const label = h('span', {}, t('Copy'));
        return h('div', { class: 'flex flex-col gap-2' },
            h('div', { class: 'flex items-center justify-between gap-2' },
                h('span', { class: 'text-sm font-medium' }, t('Raw saved settings (read-only)')),
                h('button', { type: 'button', class: buttonClass.ghost, onclick: async () => { await navigator.clipboard?.writeText(text); label.textContent = t('Copied'); } }, icon('copy', 14), label)),
            h('pre', { class: 'max-h-72 overflow-auto rounded-lg bg-inverse p-3 text-xs text-fg-inverse', dir: 'ltr' }, text),
            h('p', { class: 'text-xs text-fg-secondary' }, t('Shows the last saved version. Super admins only.')));
    }

    // ---- problems / issues ---------------------------------------------
    function issueList() {
        const list = [];
        for (const zone of boot.zoneOrder) {
            (state.zones[zone] ?? []).forEach((block, index) => {
                const type = boot.types[block.type];
                for (const requirement of blockIssues(block)) {
                    const fields = requirement.split('|').map((key) => type?.fields.find((f) => f.key === key)?.label ?? key).join(` ${t('or')} `);
                    list.push({ zone, id: block.id, message: `${typeLabel(block.type)}: ${t(':field is required to publish.', { field: fields })}` });
                }
                for (const key of Object.keys(state.errors).filter((k) => k.startsWith(`zones.${zone}.${index}.`))) list.push({ zone, id: block.id, message: `${typeLabel(block.type)}: ${state.errors[key]}` });
            });
        }
        return list;
    }

    function paintProblems() {
        const issues = issueList();
        $('[data-pb-problems]').hidden = issues.length === 0;
        $('[data-pb-problems-title]').textContent = t(issues.length === 1 ? 'Fix :n issue before publishing' : 'Fix :n issues before publishing', { n: issues.length });
        $('[data-pb-problems-list]').textContent = issues.map((issue) => issue.message).join(' · ');
        const chip = $('[data-pb-issues]');
        if (chip) {
            chip.hidden = issues.length === 0;
            $('[data-pb-issues-label]').textContent = t(issues.length === 1 ? ':n issue' : ':n issues', { n: issues.length });
        }
        root.querySelectorAll('[data-pb-publish]').forEach((button) => { button.disabled = issues.length > 0; });
        return issues;
    }

    function goToFirstIssue() {
        const [first] = issueList();
        if (!first) return;
        select({ zone: first.zone, id: first.id });
        requestAnimationFrame(() => form.querySelector('p.text-fg-danger')?.closest('[data-field-path]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
    }
    $('[data-pb-first-issue]')?.addEventListener('click', goToFirstIssue);
    $('[data-pb-issues]')?.addEventListener('click', goToFirstIssue);

    // ---- add-section gallery (249:1923) ----------------------------------
    let galleryZone = null;
    let galleryGroup = 'all';
    const recent = (() => { try { return JSON.parse(window.localStorage.getItem('nawy.pb.recent') ?? '[]'); } catch { return []; } })();

    function openGallery(zone = null) {
        galleryZone = zone ?? state.selected?.zone ?? boot.zoneOrder.find((z) => boot.allowed[z].length) ?? boot.zoneOrder[0];
        gallery.querySelector('[data-pb-gallery-search]').value = '';
        galleryGroup = 'all';
        paintGallery();
        gallery.showModal();
        gallery.querySelector('[data-pb-gallery-search]').focus();
    }

    function galleryCard(type, def, ok) {
        const tags = [];
        if (def.kind === 'system') tags.push([t('Personalised'), 'bg-info-subtle text-fg-info']);
        if (def.kind === 'ad') tags.push([t('Filled by ad bookings'), 'bg-info-subtle text-fg-info']);
        if ((def.required ?? []).some((r) => r.includes('flash_sale'))) tags.push([t('Needs a flash sale'), 'bg-info-subtle text-fg-info']);
        const card = h('button', {
            type: 'button', disabled: !ok, draggable: ok ? 'true' : null,
            class: `group/card relative flex flex-col gap-2 rounded-xl border border-line bg-surface p-2 text-start transition ${ok ? 'hover:border-line-brand hover:shadow-md focus:outline-none focus-visible:border-line-brand focus-visible:ring-2 focus-visible:ring-brand/30' : 'cursor-not-allowed opacity-50'}`,
            onclick: () => addBlock(galleryZone, type),
        },
        thumbnail(type, 'aspect-[16/9] w-full'),
        ok ? h('span', { class: 'absolute end-3 top-3 hidden items-center gap-1 rounded-md bg-brand px-2 py-1 text-xs font-semibold text-fg-on-brand group-hover/card:flex group-focus-visible/card:flex' }, icon('plus', 12), t('Add')) : null,
        h('span', { class: 'flex flex-col gap-0.5 px-1 pb-1' },
            h('span', { class: 'text-[15px] font-medium' }, def.label),
            h('span', { class: 'line-clamp-2 text-xs text-fg-secondary' }, def.description),
            tags.length ? h('span', { class: 'mt-1 flex flex-wrap gap-1' }, tags.map(([label, tone]) => h('span', { class: `rounded px-1.5 text-[11px] font-medium ${tone}` }, label))) : null));
        card.addEventListener('dragstart', (e) => { dragged = { fromGallery: true, type }; e.dataTransfer.setData('text/plain', type); setTimeout(() => gallery.close(), 0); });
        card.addEventListener('dragend', () => { dragged = null; dropLine.remove(); });
        return card;
    }

    function paintGallery() {
        const query = gallery.querySelector('[data-pb-gallery-search]').value.trim().toLowerCase();
        const allowed = boot.allowed[galleryZone] ?? [];
        const after = state.selected?.zone === galleryZone ? blockAt(state.selected) : null;
        gallery.querySelector('[data-pb-gallery-zone]').textContent = after ? t('to the :zone zone · after “:section”', { zone: zoneLabel(galleryZone), section: typeLabel(after.type) }) : t('to the :zone zone', { zone: zoneLabel(galleryZone) });
        const matches = ([type, def]) => !query || `${def.label} ${def.description} ${type}`.toLowerCase().includes(query);
        const entries = [['all', t('All sections'), Object.keys(boot.types).length], ['recent', t('Recently used'), recent.length], ...Object.entries(boot.groups).map(([key, label]) => [key, label, Object.values(boot.types).filter((def) => def.group === key).length])];
        gallery.querySelector('[data-pb-gallery-groups]').replaceChildren(...entries.map(([key, label, count], i) => h('button', {
            type: 'button', class: `flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm ${galleryGroup === key ? 'bg-surface font-medium shadow-xs ring-1 ring-line' : 'text-fg-secondary hover:bg-surface hover:text-fg'} ${i === 1 ? 'mb-2' : ''}`,
            onclick: () => { galleryGroup = key; paintGallery(); },
        }, label, h('span', { class: 'text-xs text-fg-muted' }, String(count)))));
        const groups = galleryGroup === 'recent' ? [['recent', t('Recently used'), recent.filter((type) => boot.types[type]).map((type) => [type, boot.types[type]])]]
            : Object.entries(boot.groups).filter(([key]) => galleryGroup === 'all' || galleryGroup === key).map(([key, label]) => [key, label, Object.entries(boot.types).filter(([, def]) => def.group === key)]);
        const sections = groups.map(([, label, types]) => {
            const shown = types.filter(matches);
            if (!shown.length) return null;
            const fit = shown.filter(([type]) => allowed.includes(type)).length;
            const note = fit === shown.length ? h('span', { class: 'text-xs font-normal text-fg-secondary' }, t(':n types · all fit the :zone zone', { n: shown.length, zone: zoneLabel(galleryZone) }))
                : fit === 0 ? h('span', { class: 'text-xs font-normal text-fg-danger' }, t('Not available here — :zones only', { zones: boot.zoneOrder.filter((z) => boot.allowed[z].includes(shown[0][0])).map(zoneLabel).join(', ') || t('other templates') }))
                : h('span', { class: 'text-xs font-normal text-fg-secondary' }, t(':fit of :n fit the :zone zone', { fit, n: shown.length, zone: zoneLabel(galleryZone) }));
            return h('section', { class: 'flex flex-col gap-3' },
                h('h3', { class: 'flex flex-wrap items-baseline gap-2 text-[17px] font-semibold' }, label, note),
                h('div', { class: 'grid grid-cols-2 gap-3 lg:grid-cols-4' }, shown.sort(([a], [b]) => Number(!allowed.includes(a)) - Number(!allowed.includes(b))).map(([type, def]) => galleryCard(type, def, allowed.includes(type)))));
        }).filter(Boolean);
        gallery.querySelector('[data-pb-gallery-grid]').replaceChildren(...(sections.length ? sections : [h('p', { class: 'p-6 text-center text-sm text-fg-secondary' }, t('No section matches.'))]));
    }

    function addBlock(zone, type, at = null) {
        if (!boot.allowed[zone]?.includes(type)) { toast(t('Not available in the :zone zone', { zone: zoneLabel(zone) }), 'danger'); return; }
        const block = { id: uid(), type, hidden: false, locales: [], settings: defaults(boot.types[type]?.fields ?? []), raw: {}, __touched: true };
        const selectedIndex = state.selected?.zone === zone ? indexOf(zone, state.selected.id) : -1;
        const position = at ?? (selectedIndex >= 0 ? selectedIndex + 1 : state.zones[zone].length);
        state.zones[zone].splice(position, 0, block);
        state.errors = {};
        if (gallery.open) gallery.close();
        try {
            const next = [type, ...recent.filter((x) => x !== type)].slice(0, 8);
            recent.splice(0, recent.length, ...next);
            window.localStorage.setItem('nawy.pb.recent', JSON.stringify(next));
        } catch {
            // Per-browser convenience only.
        }
        markDirty();
        select({ zone, id: block.id });
        announce(t(':type added', { type: typeLabel(type) }));
    }

    gallery.querySelector('[data-pb-gallery-search]').addEventListener('input', paintGallery);
    gallery.querySelector('[data-pb-gallery-search]').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            gallery.querySelector('[data-pb-gallery-grid] button:not([disabled])')?.click();
        }
    });
    gallery.querySelectorAll('[data-pb-gallery-close]').forEach((button) => button.addEventListener('click', () => gallery.close()));
    gallery.addEventListener('click', (e) => { if (e.target === gallery) gallery.close(); });
    $('[data-pb-add-section]')?.addEventListener('click', () => openGallery());

    // ---- page fields ----------------------------------------------------
    root.querySelectorAll('[data-pb-page]').forEach((input) => {
        input.addEventListener('input', () => {
            const locale = input.dataset.pbPageLocale ?? pageLocale;
            state.pages[locale] = state.pages[locale] ?? {};
            state.pages[locale][input.dataset.pbPage] = input.value;
            if (locale === pageLocale && input.dataset.pbPage === 'title') $('[data-pb-title]').textContent = input.value;
            markDirty();
        });
    });

    // ---- save -----------------------------------------------------------
    async function save({ auto = false, overwrite = false } = {}) {
        if (state.saving || !canEdit || (auto && state.conflict)) return false;
        clearTimeout(autosaveTimer);
        state.saving = true;
        paintSaveState();
        const noteInput = $('[data-pb-note]');
        const sent = snapshot();
        const payload = {
            pages: state.pages,
            note: auto ? t('Autosave') : (noteInput?.value || null),
            base_revision: state.revision,
            overwrite,
            zones: Object.fromEntries(Object.entries(state.zones).map(([zone, blocks]) => [zone, blocks.map((b) => ({ id: b.id, type: b.type, hidden: Boolean(b.hidden), locales: b.locales ?? [], settings: b.settings }))])),
        };
        const result = await requestJson(boot.urls.save, { method: 'POST', body: payload });
        state.saving = false;
        if (auto) lastAutosave = Date.now();
        if (result.status === 409 && result.data?.conflict) {
            showConflict(result.data.conflict);
            paintSaveState();
            return false;
        }
        if (result.ok) {
            const editedMeanwhile = snapshot() !== sent;
            state.revision = result.data.revision?.number ?? state.revision;
            hideConflict();
            draftShown = false;
            state.errors = {};
            state.failed = false;
            state.dirty = editedMeanwhile;
            if (!editedMeanwhile) {
                state.zones = result.data.zones;
                lastSnapshot = snapshot();
            }
            state.savedAt = result.data.savedAt;
            state.sinceLive = result.data.sinceLive;
            if (state.selected && !blockAt(state.selected)) state.selected = null;
            if (noteInput && !auto) noteInput.value = '';
            addRevision(result.data.revision);
            if (!auto) toast(result.data.message, 'success');
            paintAll();
            refreshPreview();
            return true;
        }
        if (result.status === 422) {
            state.errors = result.data?.errors ? Object.fromEntries(Object.entries(result.data.errors).map(([key, messages]) => [key, messages[0]])) : {};
            const first = Object.keys(state.errors).find((key) => key.startsWith('zones.'));
            if (first) {
                const [, zone, index] = first.split('.');
                const block = state.zones[zone]?.[Number(index)];
                if (block) { state.selected = { zone, id: block.id }; state.panel = 'section'; }
            } else if (Object.keys(state.errors).length) {
                state.panel = 'page';
            }
            const pageErrors = $('[data-pb-page-errors]');
            const messages = Object.entries(state.errors).filter(([key]) => !key.startsWith('zones.')).map(([, message]) => message);
            pageErrors.hidden = messages.length === 0;
            pageErrors.replaceChildren(...messages.map((message) => h('p', {}, message)));
            if (!auto) toast(t('Some fields need attention. Nothing was saved.'), 'danger');
        } else {
            state.failed = true;
            if (!auto) toast(result.data?.message ?? t('Saving failed. Try again.'), 'danger');
        }
        paintAll();
        if (!auto) requestAnimationFrame(() => form.querySelector('p.text-fg-danger')?.closest('[data-field-path]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
        return false;
    }

    function addRevision(revision) {
        const list = $('[data-pb-revisions]');
        if (!list || !revision || list.querySelector(`[data-revision="${revision.number}"]`)) return;
        list.prepend(h('li', { class: 'flex gap-3 border-b border-line px-4 py-3', dataset: { revision: revision.number } },
            h('span', { class: 'grid size-8 shrink-0 place-items-center rounded-full bg-subtle text-xs font-semibold text-fg-secondary' }, icon('file', 14)),
            h('div', { class: 'flex min-w-0 flex-col gap-0.5' },
                h('p', { class: 'flex items-center gap-2 text-[15px] font-medium' }, t('Current draft'), h('span', { class: 'rounded-md bg-warning-subtle px-1.5 text-xs font-medium text-fg-warning' }, `${t('Draft')} · v${revision.number}`)),
                h('p', { class: 'text-xs text-fg-secondary' }, `${revision.at}${revision.note ? ` · ${revision.note}` : ''}`))));
    }

    root.querySelectorAll('[data-pb-save]').forEach((button) => button.addEventListener('click', () => save()));
    $('[data-pb-status]')?.addEventListener('click', () => { if (state.failed) save(); });
    $('[data-pb-undo]')?.addEventListener('click', undo);
    $('[data-pb-redo]')?.addEventListener('click', redo);
    $('[data-pb-discard]')?.addEventListener('click', () => {
        if (window.confirm(t('Discard all unsaved changes?'))) {
            state.dirty = false;
            window.location.reload();
        }
    });
    root.querySelectorAll('[data-pb-open-panel]').forEach((button) => button.addEventListener('click', () => openPanel(button.dataset.pbOpenPanel)));
    root.querySelectorAll('[data-pb-close-panel]').forEach((button) => button.addEventListener('click', () => { state.panel = 'section'; paintPanel(); }));

    const outlineSearch = $('[data-pb-outline-search]');
    $('[data-pb-outline-search-toggle]')?.addEventListener('click', () => {
        outlineSearch.hidden = !outlineSearch.hidden;
        if (!outlineSearch.hidden) outlineSearch.focus();
        else { state.filter = ''; outlineSearch.value = ''; paintOutline(); }
    });
    outlineSearch?.addEventListener('input', () => { state.filter = outlineSearch.value; paintOutline(); });

    document.addEventListener('keydown', (e) => {
        const typing = e.target.closest?.('input, textarea, select, [contenteditable="true"]');
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            save();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !typing) {
            e.preventDefault();
            if (e.shiftKey) redo();
            else undo();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y' && !typing) {
            e.preventDefault();
            redo();
        } else if (e.key === '/' && !typing && !gallery.open && canEdit) {
            e.preventDefault();
            openGallery();
        } else if (e.key === 'Escape' && !gallery.open && !typing && state.selected) {
            state.selected = null;
            paintOutline();
            paintPanel();
        }
    });
    window.addEventListener('beforeunload', (e) => {
        if (state.dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    root.querySelectorAll('form[data-pb-requires-save]').forEach((element) => {
        element.addEventListener('submit', async (e) => {
            if (!state.dirty) return;
            e.preventDefault();
            if (await save()) { state.dirty = false; element.submit(); }
        });
    });
    root.querySelectorAll('form[data-pb-confirm]').forEach((element) => {
        element.addEventListener('submit', (e) => { if (!window.confirm(element.dataset.pbConfirm)) e.preventDefault(); });
    });
    root.querySelectorAll('form[data-pb-leave], a[data-pb-leave-link]').forEach((element) => {
        element.addEventListener(element.tagName === 'FORM' ? 'submit' : 'click', (e) => {
            if (e.defaultPrevented) return;
            if (state.dirty && !window.confirm(t('You have unsaved changes. Continue without saving them?'))) e.preventDefault();
            else state.dirty = false;
        });
    });

    // ---- publish dialog (250:4897) --------------------------------------
    const publishDialog = $('[data-pb-publish-dialog]');
    function openPublish(mode) {
        if (!publishDialog) return;
        const formEl = publishDialog.querySelector('[data-pb-publish-form]');
        const radio = formEl.querySelector(`input[name="pb_mode"][value="${mode}"]:not([disabled])`) ?? formEl.querySelector('input[name="pb_mode"][value="now"]');
        radio.checked = true;
        paintPublish();
        publishDialog.showModal();
    }
    function paintPublish() {
        const formEl = publishDialog.querySelector('[data-pb-publish-form]');
        const mode = formEl.querySelector('input[name="pb_mode"]:checked')?.value ?? 'now';
        formEl.action = mode === 'schedule' ? formEl.dataset.scheduleUrl : formEl.dataset.publishUrl;
        publishDialog.querySelector('[data-pb-schedule-fields]').hidden = mode !== 'schedule';
        const issues = issueList();
        const blocks = Object.values(state.zones).flat();
        const gaps = blocks.filter((b) => !b.hidden).flatMap((b) => blockGaps(b).map((gap) => ({ ...gap, type: typeLabel(b.type) })));
        const hidden = blocks.filter((b) => b.hidden);
        const check = (ok, text, tone = ok ? 'text-fg-success' : 'text-fg-danger') => h('li', { class: 'flex items-start gap-2' }, h('span', { class: tone }, icon(ok ? 'check' : 'alert-circle', 16)), h('span', {}, text));
        publishDialog.querySelector('[data-pb-checks]').replaceChildren(...[
            check(issues.length === 0, issues.length === 0 ? t(':n sections valid, required fields complete', { n: blocks.length }) : t(':n issues block publishing', { n: issues.length })),
            check(gaps.length === 0, gaps.length === 0 ? t('English and Arabic content complete') : t('Arabic or English content missing in :n fields', { n: gaps.length })),
            ...gaps.slice(0, 5).map((gap) => h('li', { class: 'ps-6 text-xs text-fg-secondary' }, `${gap.type} — ${gap.locale === 'ar' ? t('Arabic') : t('English')}: ${gap.label}`)),
            hidden.length ? check(false, t(':n hidden sections (:names) will not be published', { n: hidden.length, names: hidden.slice(0, 3).map((b) => typeLabel(b.type)).join(', ') }), 'text-fg-warning') : null,
        ].filter(Boolean));
        const forceRow = publishDialog.querySelector('[data-pb-force-row]');
        if (gaps.length && boot.superAdmin && !forceRow) {
            publishDialog.querySelector('[data-pb-checks]').after(h('label', { class: 'mt-2 flex items-center gap-2 text-sm font-medium text-fg', dataset: { pbForceRow: '' } }, h('input', { type: 'checkbox', name: 'force', value: '1', class: 'size-4 accent-brand', onchange: () => paintPublish() }), t('Publish anyway (super admin)')));
        }
        const live = state.sinceLive;
        publishDialog.querySelector('[data-pb-changes]').textContent = live ? t('Changes since live: :edited edited · :added added · :removed removed · :moved moved', live) : t('First publish of this page.');
        const forced = formEl.querySelector('input[name="force"]')?.checked;
        const submit = publishDialog.querySelector('[data-pb-publish-submit]');
        submit.disabled = issues.length > 0 || (gaps.length > 0 && !forced);
        submit.textContent = mode === 'schedule' ? t('Schedule') : t('Publish now');
    }
    if (publishDialog) {
        publishDialog.querySelectorAll('input[name="pb_mode"]').forEach((radio) => radio.addEventListener('change', paintPublish));
        publishDialog.querySelectorAll('[data-pb-dialog-close]').forEach((button) => button.addEventListener('click', () => publishDialog.close()));
        publishDialog.querySelector('[data-pb-publish-form]').addEventListener('submit', async (e) => {
            if (!state.dirty) return;
            e.preventDefault();
            if (await save()) { state.dirty = false; e.target.submit(); }
        });
    }
    root.querySelectorAll('[data-pb-publish]').forEach((button) => button.addEventListener('click', () => openPublish(button.dataset.pbPublish)));

    // ---- live preview ---------------------------------------------------
    const frame = $('[data-pb-frame]');
    const frameBox = $('[data-pb-frame-box]');
    const holder = $('[data-pb-frame-holder]');
    let previewLocale = pageLocale;
    let device = 'desktop';
    let showHidden = false;
    const WIDTHS = { desktop: 1440, tablet: 768, mobile: 390 };

    let draftShown = false;
    let lastScroll = 0;
    let restoreScroll = false;
    function previewSrc() {
        const urls = showHidden ? boot.previewUrlsHidden : boot.previewUrls;
        return `${urls[previewLocale] ?? urls[pageLocale]}${draftShown ? `&draft=${boot.draftKey}` : ''}&t=${Date.now()}`;
    }
    function fitFrame() {
        if (!frame || !holder.clientWidth) return;
        const width = WIDTHS[device];
        const scale = Math.min(1, (holder.clientWidth - 2) / width);
        const height = Math.max(holder.clientHeight, 600);
        frame.style.width = `${width}px`;
        frame.style.height = `${height / scale}px`;
        frame.style.transform = `scale(${scale})`;
        frameBox.style.width = `${width * scale}px`;
        frameBox.style.height = `${height}px`;
        $('[data-pb-zoom]').textContent = `${width} px · ${Math.round(scale * 100)}%`;
    }
    function refreshPreview() {
        if (!frame || !previewOn) return;
        restoreScroll = true;
        frame.src = previewSrc();
    }
    function paintPreviewControls() {
        root.querySelectorAll('[data-pb-device]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.pbDevice === device)));
        root.querySelectorAll('[data-pb-preview-locale]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.pbPreviewLocale === previewLocale)));
        const tab = $('[data-pb-preview-tab]');
        if (tab) tab.href = (showHidden ? boot.previewUrlsHidden : boot.previewUrls)[previewLocale];
    }
    root.querySelectorAll('[data-pb-device]').forEach((button) => button.addEventListener('click', () => { device = button.dataset.pbDevice; paintPreviewControls(); fitFrame(); }));
    root.querySelectorAll('[data-pb-preview-locale]').forEach((button) => button.addEventListener('click', () => { previewLocale = button.dataset.pbPreviewLocale; paintPreviewControls(); refreshPreview(); }));
    $('[data-pb-show-hidden]')?.addEventListener('change', (e) => { showHidden = e.target.checked; paintPreviewControls(); refreshPreview(); });
    $('[data-pb-preview-refresh]')?.addEventListener('click', refreshPreview);
    window.addEventListener('resize', fitFrame);
    if (window.ResizeObserver && holder) new ResizeObserver(fitFrame).observe(holder);

    // ---- feedback -------------------------------------------------------
    const live = h('div', { class: 'sr-only', 'aria-live': 'polite' });
    root.append(live);
    function announce(message) {
        live.textContent = message;
    }
    function toast(message, tone, action = null) {
        const box = h('div', { role: 'status', class: `fixed bottom-24 start-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg px-4 py-2 text-sm font-medium shadow-lg rtl:translate-x-1/2 ${tone === 'danger' ? 'bg-danger text-white' : 'bg-inverse text-fg-inverse'}` },
            message, action ? h('button', { type: 'button', class: 'font-semibold text-brand underline', onclick: () => { action.action(); box.remove(); } }, action.label) : null);
        document.body.append(box);
        setTimeout(() => box.remove(), 5000);
    }

    // ---- preview bridge (storefront features/page-builder/preview-bridge.tsx) ----
    function postToPreview(message) {
        frame?.contentWindow?.postMessage({ source: 'nawy-editor', ...message }, '*');
    }
    window.addEventListener('message', (e) => {
        if (e.source !== frame?.contentWindow || e.data?.source !== 'nawy-preview') return;
        if (e.data.type === 'pb:select' && e.data.id) {
            const zone = boot.zoneOrder.find((z) => (state.zones[z] ?? []).some((b) => b.id === e.data.id));
            if (zone) {
                select({ zone, id: e.data.id }, { fromPreview: true });
                postToPreview({ type: 'pb:select', id: e.data.id, label: typeLabel(blockAt({ zone, id: e.data.id })?.type) });
                outline.querySelector(`[data-pb-row="${CSS.escape(e.data.id)}"]`)?.scrollIntoView({ block: 'nearest' });
            }
        } else if (e.data.type === 'pb:scroll') {
            lastScroll = e.data.y ?? 0;
        } else if (e.data.type === 'pb:ready') {
            if (restoreScroll && lastScroll) postToPreview({ type: 'pb:scrollTo', y: lastScroll });
            restoreScroll = false;
            if (state.selected) postToPreview({ type: 'pb:select', id: state.selected.id, label: typeLabel(blockAt(state.selected)?.type) });
        }
    });

    /** Live preview while typing: unsaved zones go to the draft-preview endpoint, then the iframe reloads with `&draft=`. */
    let draftTimer = null;
    let draftSeq = 0;
    function scheduleDraftPreview() {
        if (!canEdit || !frame || !previewOn) return;
        clearTimeout(draftTimer);
        draftTimer = setTimeout(async () => {
            const seq = ++draftSeq;
            const zones = Object.fromEntries(Object.entries(state.zones).map(([zone, blocks]) => [zone, blocks.map((b) => ({ id: b.id, type: b.type, hidden: Boolean(b.hidden), locales: b.locales ?? [], settings: b.settings }))]));
            const result = await requestJson(boot.urls.draft, { method: 'POST', body: { key: boot.draftKey, zones } });
            if (result.ok && seq === draftSeq) {
                draftShown = true;
                refreshPreview();
            }
        }, 700);
    }

    // ---- concurrent editing ----------------------------------------------
    const conflictBox = h('div', { role: 'alert', class: 'flex flex-wrap items-center gap-3 rounded-xl border border-line bg-warning-subtle px-4 py-3', hidden: true });
    $('[data-pb-problems]')?.before(conflictBox);
    function showConflict(conflict) {
        state.conflict = conflict;
        const changed = Object.keys(conflict.sections ?? {});
        Object.values(state.zones).flat().forEach((block) => { block.__theirs = changed.includes(block.id); });
        conflictBox.hidden = false;
        conflictBox.replaceChildren(
            icon('alert', 20, 'text-fg-warning'),
            h('div', { class: 'min-w-0 flex-1' },
                h('p', { class: 'text-[15px] font-semibold' }, t(':name edited this page :ago — revision :number', { name: conflict.by ?? t('Another admin'), ago: conflict.ago ?? '', number: conflict.revision })),
                h('p', { class: 'text-sm text-fg-secondary', dataset: { pbConflictDetail: '' } }, t('Your unsaved changes were not saved, to avoid overwriting theirs.'))),
            h('button', { type: 'button', class: buttonClass.outline, onclick: () => { state.dirty = false; window.location.reload(); } }, t('Reload')),
            h('button', { type: 'button', class: buttonClass.outline, onclick: () => {
                conflictBox.querySelector('[data-pb-conflict-detail]').textContent = `${conflict.summary ?? t('No section changes')}${changed.length ? ` — ${changed.map((id) => typeLabel(Object.values(state.zones).flat().find((b) => b.id === id)?.type ?? '')).filter(Boolean).join(', ')}` : ''}`;
                paintOutline();
            } }, t('Compare')),
            h('button', { type: 'button', class: buttonClass.primary, onclick: async () => { if (window.confirm(t('Overwrite their revision :number with your version?', { number: conflict.revision }))) await save({ overwrite: true }); } }, t('Overwrite')));
        paintOutline();
    }
    function hideConflict() {
        state.conflict = null;
        conflictBox.hidden = true;
        Object.values(state.zones).flat().forEach((block) => { delete block.__theirs; });
    }
    async function pollStatus() {
        if (document.hidden || state.saving || state.conflict) return;
        const url = new URL(boot.urls.status, window.location.origin);
        url.searchParams.set('base', String(state.revision));
        const result = await requestJson(url.toString());
        if (result.ok && result.data?.revision > state.revision && !state.saving) showConflict(result.data);
    }
    if (canEdit && boot.urls.status) setInterval(pollStatus, 20000);

    function paintAll() {
        paintOutline();
        paintPanel();
        paintProblems();
        paintSaveState();
    }

    // ---- preview on / off (remembered per admin in this browser; shortcut P) ----
    function applyPreview() {
        workspace.toggleAttribute('data-preview-on', previewOn);
        $('[data-pb-canvas]').hidden = !previewOn;
        root.querySelectorAll('[data-pb-preview-toggle]').forEach((button) => button.setAttribute('aria-pressed', String(previewOn)));
        const alerts = $('[data-pb-alerts]');
        if (alerts) (previewOn ? $('[data-pb-preview]').after(alerts) : $('[data-pb-alerts-panel]').append(alerts));
        paintPanel();
        if (previewOn) {
            refreshPreview();
            requestAnimationFrame(fitFrame);
        } else if (frame) {
            frame.removeAttribute('src');
        }
    }
    function togglePreview() {
        previewOn = !previewOn;
        try { window.localStorage.setItem('nawy.pb.preview', previewOn ? '1' : '0'); } catch { /* per-browser convenience */ }
        applyPreview();
    }
    root.querySelectorAll('[data-pb-preview-toggle]').forEach((button) => button.addEventListener('click', togglePreview));
    document.addEventListener('keydown', (e) => {
        if (e.key.toLowerCase() === 'p' && !e.ctrlKey && !e.metaKey && !e.altKey && !e.target.closest?.('input, textarea, select, [contenteditable="true"]') && !gallery.open) {
            e.preventDefault();
            togglePreview();
        }
    });

    paintAll();
    paintPreviewControls();
    applyPreview();
    setTimeout(() => $('[data-pb-flash]')?.remove(), 6000);
}

function firstSelection(boot) {
    const params = new URLSearchParams(window.location.search);
    const wanted = params.get('section');
    for (const zone of boot.zoneOrder) {
        const found = (boot.zones[zone] ?? []).find((b) => b.id === wanted);
        if (found) return { zone, id: found.id };
    }
    for (const zone of boot.zoneOrder) {
        if ((boot.zones[zone] ?? []).length) return { zone, id: boot.zones[zone][0].id };
    }
    return null;
}
