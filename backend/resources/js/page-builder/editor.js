import { buttonClass, clone, h, icon, placeholderThumb, requestJson, uid } from './dom';
import { defaults, missing, renderFields } from './fields';

/**
 * Admin page builder (Content → Pages → page): outline of zones and
 * sections with drag & drop (and keyboard) reordering, hide / duplicate /
 * delete, the add-section gallery, schema-driven settings forms, save as a
 * draft revision (JSON), unsaved-changes bar and the live storefront
 * preview. State comes from the `#page-builder-state` JSON the controller
 * embeds; nothing here posts raw JSON settings for the admin to edit.
 */
export function initPageBuilder(root) {
    const boot = JSON.parse(root.querySelector('#page-builder-state').textContent);
    const i18n = JSON.parse(root.querySelector('#page-builder-i18n').textContent);
    const t = (key, params = {}) => Object.entries(params).reduce((text, [name, value]) => text.replaceAll(`:${name}`, String(value)), i18n[key] ?? key);

    const state = {
        page: { title: boot.page.title ?? '', seo_title: boot.page.seo_title ?? '', seo_description: boot.page.seo_description ?? '' },
        zones: boot.zones,
        selected: firstSelection(boot),
        dirty: false,
        saving: false,
        errors: {},
    };
    const openItems = new WeakMap();
    const openGroups = new Map();
    const labelCache = new Map();

    const outline = root.querySelector('[data-pb-outline]');
    const form = root.querySelector('[data-pb-form]');
    const pagePanel = root.querySelector('[data-pb-page-panel]');
    const saveBar = root.querySelector('[data-pb-savebar]');
    const saveButtons = root.querySelectorAll('[data-pb-save]');
    const statusLine = root.querySelector('[data-pb-status]');
    const gallery = root.querySelector('[data-pb-gallery]');
    const problemsBox = root.querySelector('[data-pb-problems]');

    // ---- helpers -------------------------------------------------------
    const typeLabel = (type) => boot.types[type]?.label ?? type;
    const thumbnail = (type, size = 'h-16 w-28') => {
        const box = h('div', { class: `${size} shrink-0 overflow-hidden rounded-lg border border-line bg-page` });
        const url = boot.types[type]?.thumbnail;
        if (url) {
            const img = h('img', { src: url, alt: '', class: 'size-full object-cover object-top', loading: 'lazy' });
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
    const hasErrorUnder = (prefix) => Object.keys(state.errors).some((key) => key === prefix || key.startsWith(`${prefix}.`) || key.startsWith(`${prefix}_`));

    async function picker(kind, { q = '', ids = [] } = {}) {
        const url = new URL(boot.urls.picker.replace('__kind__', kind), window.location.origin);
        if (q) url.searchParams.set('q', q);
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

    function markDirty() {
        if (!state.dirty) {
            state.dirty = true;
            paintSaveState();
        }
    }

    function paintSaveState() {
        saveBar.hidden = !state.dirty;
        saveButtons.forEach((button) => { button.disabled = state.saving; });
        if (statusLine) statusLine.textContent = state.saving ? t('Saving…') : state.dirty ? t('Unsaved changes') : t('All changes saved');
    }

    const ctx = {
        t,
        types: boot.types,
        childTypes: boot.childTypes,
        fieldGroups: boot.fieldGroups,
        country: boot.country,
        pageLocale: boot.page.locale,
        urls: boot.urls,
        openGroups,
        typeLabel,
        thumbnail,
        errorFor,
        hasErrorUnder,
        picker,
        labels,
        remember,
        clearErrors: (prefix) => { for (const key of Object.keys(state.errors)) if (key.startsWith(prefix)) delete state.errors[key]; },
        isOpen: (item) => openItems.get(item) ?? false,
        setOpen: (item, open) => openItems.set(item, open),
        changed: () => { markDirty(); paintOutline(); },
        rerenderForm: () => paintForm(),
        openLink: (link) => window.open(link.startsWith('http') ? link : `${boot.storefront}${link}`, '_blank', 'noopener'),
    };

    // ---- outline -------------------------------------------------------
    let dragged = null;

    function paintOutline() {
        const focused = document.activeElement?.closest?.('[data-pb-row]')?.dataset.pbRow;
        outline.replaceChildren(
            h('button', {
                type: 'button', class: `flex w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm font-semibold ${state.selected === 'page' ? 'bg-brand-subtle text-fg' : 'hover:bg-subtle'}`,
                onclick: () => select('page'),
            }, icon('settings', 16), t('Page settings & publishing')),
            ...boot.zoneOrder.map((zone) => zoneSection(zone)));
        if (focused) outline.querySelector(`[data-pb-row="${CSS.escape(focused)}"] [data-pb-row-main]`)?.focus();
    }

    function zoneSection(zone) {
        const blocks = state.zones[zone] ?? [];
        const list = h('ol', { class: 'flex min-h-10 flex-col gap-1 rounded-lg p-1', dataset: { pbZone: zone }, 'aria-label': t('Sections in :zone', { zone: boot.zoneLabels[zone] ?? zone }) });
        blocks.forEach((block, index) => list.append(row(zone, block, index, blocks.length)));
        if (blocks.length === 0) list.append(h('li', { class: 'rounded-lg border border-dashed border-line px-3 py-3 text-center text-xs text-fg-secondary' }, t('No sections yet. Drop one here or add one.')));
        list.addEventListener('dragover', (e) => {
            if (!dragged || !boot.allowed[zone].includes(dragged.type)) return;
            e.preventDefault();
            list.classList.add('bg-brand-subtle');
        });
        list.addEventListener('dragleave', (e) => { if (!list.contains(e.relatedTarget)) list.classList.remove('bg-brand-subtle'); });
        list.addEventListener('drop', (e) => {
            list.classList.remove('bg-brand-subtle');
            if (!dragged || !boot.allowed[zone].includes(dragged.type)) return;
            e.preventDefault();
            const target = e.target.closest('[data-pb-row]');
            let to = target ? indexOf(zone, target.dataset.pbRow) : blocks.length;
            if (target) {
                const rect = target.getBoundingClientRect();
                if (e.clientY > rect.top + rect.height / 2) to += 1;
            }
            moveBlock(dragged.zone, dragged.id, zone, to);
        });
        return h('section', { class: 'flex flex-col gap-1' },
            h('div', { class: 'flex items-center justify-between gap-2 px-1 pt-3' },
                h('h3', { class: 'text-[11px] font-semibold uppercase tracking-wide text-fg-secondary' }, t('Zone: :zone', { zone: boot.zoneLabels[zone] ?? zone }), h('span', { class: 'ms-1 font-normal normal-case' }, `(${blocks.length})`)),
                h('button', { type: 'button', class: buttonClass.ghost, onclick: () => openGallery(zone) }, icon('plus', 14), t('Add section'))),
            list);
    }

    function row(zone, block, index, count) {
        const type = boot.types[block.type];
        const selected = state.selected !== 'page' && state.selected?.id === block.id;
        const needs = missing(type?.required, block.settings);
        const scheduled = block.settings?.visibility?.starts_at || block.settings?.visibility?.ends_at;
        const errors = hasErrorUnder(`zones.${zone}.${index}`);
        const title = block.settings?.[`title_${boot.page.locale}`] ?? block.settings?.title_en ?? block.settings?.title_ar ?? block.settings?.title ?? '';
        const item = h('li', {
            class: `group relative flex flex-wrap items-center gap-1 rounded-lg border ${selected ? 'border-line-brand bg-brand-subtle' : 'border-transparent hover:border-line hover:bg-surface'} ${block.hidden ? 'opacity-60' : ''}`,
            draggable: 'true', dataset: { pbRow: block.id },
        },
        h('span', { class: 'cursor-grab ps-1 text-fg-muted', title: t('Drag to reorder') }, icon('grip', 15)),
        h('button', {
            type: 'button', class: 'flex min-w-0 flex-1 items-center gap-2 rounded-md py-1.5 text-start focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus', dataset: { pbRowMain: '' },
            'aria-current': selected ? 'true' : null, 'aria-keyshortcuts': 'Alt+ArrowUp Alt+ArrowDown',
            onclick: () => select({ zone, id: block.id }),
            onkeydown: (e) => {
                if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                    e.preventDefault();
                    moveBlock(zone, block.id, zone, index + (e.key === 'ArrowUp' ? -1 : 2));
                }
            },
        },
        thumbnail(block.type, 'h-8 w-12'),
        h('span', { class: 'flex min-w-0 flex-col' },
            h('span', { class: 'truncate text-sm font-medium' }, `${index + 1}. ${typeLabel(block.type)}`),
            title ? h('span', { class: 'truncate text-xs text-fg-secondary' }, title) : null)),
        errors ? h('span', { class: 'text-fg-danger', title: t('Has errors') }, icon('alert', 14)) : needs.length ? h('span', { class: 'text-fg-warning', title: t('Needs: :list', { list: needs.join(', ') }) }, icon('alert', 14)) : null,
        scheduled ? h('span', { class: 'text-fg-info', title: t('Scheduled') }, icon('clock', 14)) : null,
        h('span', { class: selected ? 'flex w-full items-center justify-end gap-0.5 border-t border-line-brand/40 px-1 py-0.5' : 'absolute end-1 top-1/2 hidden -translate-y-1/2 items-center rounded-lg bg-surface shadow-sm ring-1 ring-line lg:group-hover:flex lg:group-focus-within:flex' },
            h('button', { type: 'button', class: buttonClass.icon, title: t('Move up'), disabled: index === 0, onclick: () => moveBlock(zone, block.id, zone, index - 1) }, icon('up', 14)),
            h('button', { type: 'button', class: buttonClass.icon, title: t('Move down'), disabled: index === count - 1, onclick: () => moveBlock(zone, block.id, zone, index + 2) }, icon('down', 14)),
            h('button', { type: 'button', class: buttonClass.icon, title: block.hidden ? t('Show') : t('Hide'), 'aria-pressed': String(Boolean(block.hidden)), onclick: () => { block.hidden = !block.hidden; markDirty(); paintOutline(); paintForm(); } }, icon(block.hidden ? 'eye-off' : 'eye', 14)),
            h('button', { type: 'button', class: buttonClass.icon, title: t('Duplicate'), onclick: () => duplicateBlock(zone, block.id) }, icon('copy', 14)),
            h('button', { type: 'button', class: `${buttonClass.icon} hover:text-fg-danger`, title: t('Delete'), onclick: () => deleteBlock(zone, block.id) }, icon('trash', 14))));
        item.addEventListener('dragstart', (e) => {
            dragged = { zone, id: block.id, type: block.type };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', block.id);
            setTimeout(() => item.classList.add('opacity-40'), 0);
        });
        item.addEventListener('dragend', () => { dragged = null; paintOutline(); });
        return item;
    }

    function moveBlock(fromZone, id, toZone, to) {
        const from = indexOf(fromZone, id);
        if (from < 0) return;
        const [block] = state.zones[fromZone].splice(from, 1);
        if (fromZone === toZone && to > from) to -= 1;
        to = Math.max(0, Math.min(to, state.zones[toZone].length));
        state.zones[toZone].splice(to, 0, block);
        state.errors = {};
        markDirty();
        paintOutline();
        if (state.selected?.id === id) state.selected = { zone: toZone, id };
        announce(t('Moved to position :n', { n: to + 1 }));
    }

    function duplicateBlock(zone, id) {
        const index = indexOf(zone, id);
        const copy = { ...clone(state.zones[zone][index]), id: uid() };
        copy.raw = {};
        state.zones[zone].splice(index + 1, 0, copy);
        state.errors = {};
        markDirty();
        select({ zone, id: copy.id });
    }

    function deleteBlock(zone, id) {
        if (!window.confirm(t('Delete this section? You can restore it from the revision history after saving.'))) return;
        const index = indexOf(zone, id);
        state.zones[zone].splice(index, 1);
        state.errors = {};
        markDirty();
        if (state.selected?.id === id) state.selected = state.zones[zone][Math.min(index, state.zones[zone].length - 1)] ? { zone, id: state.zones[zone][Math.min(index, state.zones[zone].length - 1)].id } : 'page';
        paintOutline();
        paintForm();
    }

    function select(selection) {
        state.selected = selection;
        paintOutline();
        paintForm();
        root.querySelector('[data-pb-editor-panel]')?.scrollTo({ top: 0 });
    }

    // ---- settings form -------------------------------------------------
    function paintForm() {
        const scroller = root.querySelector('[data-pb-editor-panel]');
        const scroll = scroller?.scrollTop ?? 0;
        if (state.selected === 'page' || !blockAt(state.selected)) {
            form.hidden = true;
            pagePanel.hidden = false;
            return;
        }
        pagePanel.hidden = true;
        form.hidden = false;
        const { zone } = state.selected;
        const block = blockAt(state.selected);
        const index = indexOf(zone, block.id);
        const type = boot.types[block.type] ?? { label: block.type, description: '', fields: [], required: [] };
        block.settings = block.settings && typeof block.settings === 'object' && !Array.isArray(block.settings) ? block.settings : {};
        const needs = missing(type.required, block.settings);
        const raw = boot.superAdmin ? rawSettings(block) : null;

        form.replaceChildren(
            h('div', { class: 'overflow-hidden rounded-xl border border-line bg-surface' },
                h('div', { class: 'relative aspect-[16/6] w-full bg-page' }, (() => {
                    const box = h('div', { class: 'size-full' });
                    if (type.thumbnail) {
                        const img = h('img', { src: type.thumbnail, alt: t('Reference image of :type', { type: type.label }), class: 'size-full object-contain' });
                        img.addEventListener('error', () => box.replaceChildren(placeholderThumb(type.label, 'text-sm')));
                        box.append(img);
                    } else {
                        box.append(placeholderThumb(t('Reference image coming from the design'), 'text-sm'));
                    }
                    return box;
                })()),
                h('div', { class: 'flex flex-wrap items-start justify-between gap-3 p-4' },
                    h('div', { class: 'min-w-0' },
                        h('p', { class: 'text-xs font-medium uppercase tracking-wide text-fg-secondary' }, `${boot.groups[type.group] ?? type.group} · ${t('Zone: :zone', { zone: boot.zoneLabels[zone] ?? zone })} · #${index + 1}`),
                        h('h2', { class: 'text-lg font-semibold' }, type.label),
                        h('p', { class: 'text-sm text-fg-secondary' }, type.description)),
                    h('div', { class: 'flex flex-wrap items-center gap-2' },
                        block.hidden ? h('span', { class: 'inline-flex items-center gap-1 rounded-full bg-subtle px-2 py-0.5 text-xs font-medium' }, icon('eye-off', 12), t('Hidden')) : null,
                        h('label', { class: 'flex items-center gap-2 text-sm' }, h('input', { type: 'checkbox', class: 'size-4 accent-brand', checked: !block.hidden, onchange: (e) => { block.hidden = !e.target.checked; markDirty(); paintOutline(); paintForm(); } }), t('Visible on the page')))),
                needs.length ? h('div', { class: 'flex items-start gap-2 border-t border-line bg-warning-subtle px-4 py-2 text-sm text-fg-warning', role: 'status' }, icon('alert', 16), t('Needed before publishing: :list', { list: needs.join(', ') })) : null),
            renderFields(type.fields, block.settings, `zones.${zone}.${index}.settings`, { ...ctx, blockKey: block.id }, { grouped: true, extraGroups: raw ? { advanced: raw } : {} }));
        if (scroller) scroller.scrollTop = scroll;
    }

    function rawSettings(block) {
        const text = JSON.stringify(block.raw ?? {}, null, 2);
        return h('div', { class: 'flex flex-col gap-2' },
            h('div', { class: 'flex items-center justify-between gap-2' },
                h('span', { class: 'text-sm font-medium' }, t('Raw saved settings (read-only)')),
                h('button', { type: 'button', class: buttonClass.ghost, onclick: async (e) => { await navigator.clipboard?.writeText(text); e.target.textContent = t('Copied'); } }, icon('copy', 14), t('Copy'))),
            h('pre', { class: 'max-h-72 overflow-auto rounded-lg bg-inverse p-3 text-xs text-fg-inverse', dir: 'ltr' }, text),
            h('p', { class: 'text-xs text-fg-secondary' }, t('Shows the last saved version. Super admins only.')));
    }

    // ---- add-section gallery --------------------------------------------
    let galleryZone = null;
    let galleryGroup = 'all';

    function openGallery(zone) {
        galleryZone = zone;
        gallery.querySelector('[data-pb-gallery-search]').value = '';
        galleryGroup = 'all';
        paintGallery();
        gallery.showModal();
        gallery.querySelector('[data-pb-gallery-search]').focus();
    }

    function paintGallery() {
        const query = gallery.querySelector('[data-pb-gallery-search]').value.trim().toLowerCase();
        gallery.querySelector('[data-pb-gallery-zone]').textContent = t('Zone: :zone', { zone: boot.zoneLabels[galleryZone] ?? galleryZone });
        const nav = gallery.querySelector('[data-pb-gallery-groups]');
        const allowed = boot.allowed[galleryZone] ?? [];
        nav.replaceChildren(...[['all', t('All sections')], ...Object.entries(boot.groups)].map(([key, label]) => {
            const count = Object.entries(boot.types).filter(([type, def]) => (key === 'all' || def.group === key) && allowed.includes(type)).length;
            return h('button', { type: 'button', class: `flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm ${galleryGroup === key ? 'bg-brand-subtle font-semibold' : 'hover:bg-subtle'} ${count === 0 ? 'text-fg-muted' : ''}`, onclick: () => { galleryGroup = key; paintGallery(); } },
                label, h('span', { class: 'text-xs text-fg-secondary' }, String(count)));
        }));
        const cards = Object.entries(boot.types)
            .filter(([type, def]) => (galleryGroup === 'all' || def.group === galleryGroup) && (!query || `${def.label} ${def.description} ${type}`.toLowerCase().includes(query)))
            .sort(([a], [b]) => Number(!allowed.includes(a)) - Number(!allowed.includes(b)));
        gallery.querySelector('[data-pb-gallery-grid]').replaceChildren(...(cards.length ? cards.map(([type, def]) => {
            const ok = allowed.includes(type);
            return h('button', {
                type: 'button', disabled: !ok, title: ok ? '' : t('Not available in the :zone zone', { zone: boot.zoneLabels[galleryZone] ?? galleryZone }),
                class: `flex flex-col overflow-hidden rounded-xl border border-line bg-surface text-start transition ${ok ? 'hover:border-line-brand hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus' : 'cursor-not-allowed opacity-50'}`,
                onclick: () => addBlock(galleryZone, type),
            }, thumbnail(type, 'aspect-[16/9] w-full rounded-none border-0'),
            h('span', { class: 'flex flex-col gap-0.5 p-3' },
                h('span', { class: 'text-sm font-semibold' }, def.label),
                h('span', { class: 'line-clamp-2 text-xs text-fg-secondary' }, def.description),
                !ok ? h('span', { class: 'text-xs text-fg-warning' }, t('Not available in this zone')) : def.kind === 'ad' ? h('span', { class: 'text-xs text-fg-info' }, t('Filled by ad bookings')) : null));
        }) : [h('p', { class: 'col-span-full p-6 text-center text-sm text-fg-secondary' }, t('No section matches.'))]));
    }

    function addBlock(zone, type) {
        const block = { id: uid(), type, hidden: false, settings: defaults(boot.types[type]?.fields ?? []), raw: {} };
        const selectedIndex = state.selected !== 'page' && state.selected?.zone === zone ? indexOf(zone, state.selected.id) : -1;
        state.zones[zone].splice(selectedIndex >= 0 ? selectedIndex + 1 : state.zones[zone].length, 0, block);
        state.errors = {};
        gallery.close();
        markDirty();
        select({ zone, id: block.id });
        announce(t(':type added', { type: typeLabel(type) }));
    }

    gallery.querySelector('[data-pb-gallery-search]').addEventListener('input', paintGallery);
    gallery.querySelector('[data-pb-gallery-close]').addEventListener('click', () => gallery.close());
    gallery.addEventListener('click', (e) => { if (e.target === gallery) gallery.close(); });

    // ---- page fields ----------------------------------------------------
    root.querySelectorAll('[data-pb-page]').forEach((input) => {
        input.addEventListener('input', () => { state.page[input.dataset.pbPage] = input.value; markDirty(); });
    });

    // ---- save -----------------------------------------------------------
    async function save() {
        if (state.saving) return false;
        state.saving = true;
        paintSaveState();
        const note = root.querySelector('[data-pb-note]')?.value ?? '';
        const payload = {
            ...state.page,
            note: note || null,
            zones: Object.fromEntries(Object.entries(state.zones).map(([zone, blocks]) => [zone, blocks.map((b) => ({ id: b.id, type: b.type, hidden: Boolean(b.hidden), settings: b.settings }))])),
        };
        const result = await requestJson(boot.urls.save, { method: 'POST', body: payload });
        state.saving = false;
        if (result.ok) {
            state.errors = {};
            state.dirty = false;
            state.zones = result.data.zones;
            if (state.selected !== 'page' && !blockAt(state.selected)) state.selected = 'page';
            const noteInput = root.querySelector('[data-pb-note]');
            if (noteInput) noteInput.value = '';
            paintProblems(result.data.problems ?? []);
            addRevision(result.data.revision);
            toast(result.data.message, 'success');
            paintOutline();
            paintForm();
            paintSaveState();
            refreshPreview();
            return true;
        }
        state.errors = result.data?.errors ? Object.fromEntries(Object.entries(result.data.errors).map(([key, messages]) => [key, messages[0]])) : {};
        const first = Object.keys(state.errors).find((key) => key.startsWith('zones.'));
        if (first) {
            const [, zone, index] = first.split('.');
            const block = state.zones[zone]?.[Number(index)];
            if (block) state.selected = { zone, id: block.id };
        } else if (Object.keys(state.errors).length) {
            state.selected = 'page';
        }
        const pageErrors = root.querySelector('[data-pb-page-errors]');
        if (pageErrors) {
            const messages = Object.entries(state.errors).filter(([key]) => !key.startsWith('zones.')).map(([, message]) => message);
            pageErrors.hidden = messages.length === 0;
            pageErrors.replaceChildren(...messages.map((message) => h('p', {}, message)));
        }
        paintOutline();
        paintForm();
        paintSaveState();
        toast(result.status === 422 ? t('Some fields need attention. Nothing was saved.') : (result.data?.message ?? t('Saving failed. Try again.')), 'danger');
        requestAnimationFrame(() => root.querySelector('[data-pb-form] [role="alert"], [data-pb-form] .text-fg-danger')?.closest('[data-field-path]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
        return false;
    }

    function paintProblems(problems) {
        if (!problemsBox) return;
        problemsBox.hidden = problems.length === 0;
        problemsBox.querySelector('ul')?.replaceChildren(...problems.map((p) => h('li', {}, p)));
        root.querySelectorAll('[data-pb-needs-ready]').forEach((button) => { button.disabled = problems.length > 0; });
    }

    function addRevision(revision) {
        const list = root.querySelector('[data-pb-revisions]');
        if (!list || !revision) return;
        list.querySelectorAll('[data-pb-current-revision]').forEach((el) => el.remove());
        list.prepend(h('li', { class: 'flex items-center justify-between gap-2 py-2', dataset: { revision: revision.number } },
            h('span', { class: 'text-sm' }, h('span', { class: 'font-medium' }, `#${revision.number}`), ` · ${revision.at} `, h('span', { class: 'text-xs text-fg-secondary' }, revision.note ?? '')),
            h('span', { class: 'rounded-full bg-brand-subtle px-2 text-xs', dataset: { pbCurrentRevision: '' } }, t('Current draft'))));
    }

    saveButtons.forEach((button) => button.addEventListener('click', () => save()));
    root.querySelector('[data-pb-discard]')?.addEventListener('click', () => {
        if (window.confirm(t('Discard all unsaved changes?'))) {
            state.dirty = false;
            window.location.reload();
        }
    });
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            save();
        }
    });
    window.addEventListener('beforeunload', (e) => {
        if (state.dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    // Publish / schedule / copy forms save unsaved work first.
    root.querySelectorAll('form[data-pb-requires-save]').forEach((element) => {
        element.addEventListener('submit', async (e) => {
            if (!state.dirty) return;
            e.preventDefault();
            if (await save()) element.submit();
        });
    });
    root.querySelectorAll('form[data-pb-confirm]').forEach((element) => {
        element.addEventListener('submit', (e) => { if (!window.confirm(element.dataset.pbConfirm)) e.preventDefault(); });
    });
    root.querySelectorAll('form[data-pb-leave]').forEach((element) => {
        element.addEventListener('submit', (e) => {
            if (state.dirty && !window.confirm(t('You have unsaved changes. Continue without saving them?'))) e.preventDefault();
            else state.dirty = false;
        });
    });

    // ---- live preview ---------------------------------------------------
    const preview = root.querySelector('[data-pb-preview]');
    const frame = preview?.querySelector('iframe');
    let previewLocale = boot.page.locale;
    let device = 'desktop';
    const WIDTHS = { desktop: 1280, tablet: 768, mobile: 390 };

    function previewSrc() {
        const base = boot.previewUrls[previewLocale] ?? boot.previewUrls[boot.page.locale];
        return `${base}&t=${Date.now()}`;
    }
    function fitFrame() {
        if (!frame || preview.hidden) return;
        const holder = frame.parentElement;
        const width = WIDTHS[device];
        const scale = Math.min(1, holder.clientWidth / width);
        frame.style.width = `${width}px`;
        frame.style.height = `${holder.clientHeight / scale}px`;
        frame.style.transform = `scale(${scale})`;
        frame.style.transformOrigin = document.dir === 'rtl' ? 'top right' : 'top left';
    }
    function refreshPreview() {
        if (frame && !preview.hidden) frame.src = previewSrc();
    }
    function paintPreviewControls() {
        preview?.querySelectorAll('[data-pb-device]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.pbDevice === device)));
        preview?.querySelectorAll('[data-pb-preview-locale]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.pbPreviewLocale === previewLocale)));
        const open = preview?.querySelector('[data-pb-preview-open]');
        if (open) open.href = boot.previewUrls[previewLocale];
    }
    root.querySelectorAll('[data-pb-preview-toggle]').forEach((button) => button.addEventListener('click', () => {
        preview.hidden = !preview.hidden;
        root.querySelector('[data-pb-workspace]')?.toggleAttribute('data-preview-open', !preview.hidden);
        button.setAttribute('aria-pressed', String(!preview.hidden));
        if (!preview.hidden) {
            frame.src = previewSrc();
            requestAnimationFrame(fitFrame);
        }
    }));
    preview?.querySelectorAll('[data-pb-device]').forEach((button) => button.addEventListener('click', () => { device = button.dataset.pbDevice; paintPreviewControls(); fitFrame(); }));
    preview?.querySelectorAll('[data-pb-preview-locale]').forEach((button) => button.addEventListener('click', () => { previewLocale = button.dataset.pbPreviewLocale; paintPreviewControls(); refreshPreview(); }));
    preview?.querySelector('[data-pb-preview-refresh]')?.addEventListener('click', refreshPreview);
    window.addEventListener('resize', fitFrame);

    // ---- feedback -------------------------------------------------------
    const live = h('div', { class: 'sr-only', 'aria-live': 'polite' });
    root.append(live);
    function announce(message) {
        live.textContent = message;
    }
    function toast(message, tone) {
        const box = h('div', { role: 'status', class: `fixed bottom-20 start-1/2 z-50 -translate-x-1/2 rounded-lg px-4 py-2 text-sm font-medium shadow-lg rtl:translate-x-1/2 ${tone === 'success' ? 'bg-inverse text-fg-inverse' : 'bg-danger text-white'}` }, message);
        document.body.append(box);
        setTimeout(() => box.remove(), 3500);
    }

    paintOutline();
    paintForm();
    paintSaveState();
    paintPreviewControls();
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
    return 'page';
}
