import { buttonClass, clone, debounce, h, icon, inputClass, placeholderThumb, requestJson } from './dom';

/**
 * Settings forms rendered from the block schemas (BlockSchemas.php), styled
 * after the Figma field components (243:192). Content fields are bilingual:
 * their value is `{en, ar}`; the panel's language switch (EN | AR | Both)
 * decides which side(s) show. Images and links default to "same for
 * Arabic" (only `en` set). Every renderer edits `values[field.key]` in place
 * and calls `ctx.changed()`; a change of a key other fields depend on
 * (`show_if`) re-renders the form.
 */

const LOCALES = ['en', 'ar'];
const filled = (value) => value !== undefined && value !== null && value !== '' && !(Array.isArray(value) && value.length === 0);

export function isTranslation(value) {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0 && Object.keys(value).every((k) => LOCALES.includes(k));
}

/** One language of a value (falls back to the other side). */
export function pickLocale(value, locale) {
    if (!isTranslation(value)) return value;
    return filled(value[locale]) ? value[locale] : value[locale === 'ar' ? 'en' : 'ar'];
}

/** Same rule as BlockSettings::visible (PHP). */
export function isVisible(field, values) {
    for (const [key, expected] of Object.entries(field.show_if ?? {})) {
        const actual = conditionValue(values, key);
        if (Array.isArray(expected)) {
            if (!expected.map(String).includes(actual)) return false;
            continue;
        }
        const want = String(expected);
        if (want === '!') {
            if (actual === '') return false;
        } else if (want.startsWith('!')) {
            if (actual === want.slice(1)) return false;
        } else if (actual !== want) {
            return false;
        }
    }
    return true;
}

function conditionValue(values, key) {
    let value = values?.[key];
    if (isTranslation(value)) value = pickLocale(value, 'en');
    if (typeof value === 'boolean') return value ? '1' : '';
    return value === undefined || value === null || typeof value === 'object' ? '' : String(value);
}

/** Fields shown for these values: the first shown field of a key wins. */
export function shownFields(fields, values) {
    const seen = new Set();
    return fields.filter((field) => {
        if (seen.has(field.key) || !isVisible(field, values)) return false;
        seen.add(field.key);
        return true;
    });
}

function dependencyKeys(fields) {
    const keys = new Set();
    for (const field of fields) for (const key of Object.keys(field.show_if ?? {})) keys.add(key);
    return keys;
}

/** Settings of a new block / item from the schema defaults. */
export function defaults(fields) {
    const values = {};
    for (const field of fields) {
        if (field.default !== undefined && values[field.key] === undefined && isVisible(field, values)) values[field.key] = clone(field.default);
    }
    return values;
}

/** Publish requirements missing (same rule as BlockRegistry::missing). */
export function missing(required, settings) {
    const present = (key) => {
        const value = settings?.[key];
        return isTranslation(value) ? LOCALES.some((l) => filled(value[l])) : filled(value);
    };
    return (required ?? []).filter((requirement) => !requirement.split('|').some(present));
}

/**
 * Content filled in one language but empty in the other (texts only; images
 * and links fall back on purpose), as [{path, locale, label}].
 */
export function translationGaps(fields, values, prefix = '', labels = []) {
    const gaps = [];
    for (const field of shownFields(fields, values ?? {})) {
        const value = values?.[field.key];
        const path = prefix ? `${prefix}.${field.key}` : field.key;
        const label = [...labels, field.label].join(' › ');
        if (field.translatable && !['image', 'link'].includes(field.type) && isTranslation(value)) {
            for (const locale of LOCALES) {
                const other = locale === 'en' ? 'ar' : 'en';
                if (filled(value[other]) && !filled(value[locale])) gaps.push({ path, locale, label });
            }
        }
        if (field.type === 'group' && value && typeof value === 'object') gaps.push(...translationGaps(field.fields ?? [], value, path, [...labels, field.label]));
        if (field.type === 'repeater' && Array.isArray(value)) value.forEach((item, i) => gaps.push(...translationGaps(field.fields ?? [], item, `${path}.${i}`, [...labels, `${field.label} ${i + 1}`])));
    }
    return gaps;
}

/**
 * Render a list of fields over `values`. Top level (`grouped`) splits them
 * into the editor groups (Content, Products, Design, Visibility & schedule,
 * Advanced), each a collapsible section with an error count.
 */
export function renderFields(fields, values, path, ctx, { grouped = false, extraGroups = {}, summaries = {} } = {}) {
    const deps = dependencyKeys(fields);
    const visible = shownFields(fields, values);
    const container = h('div', { class: grouped ? 'flex flex-col' : 'flex flex-col gap-5' });
    const rerender = () => container.replaceWith(renderFields(fields, values, path, ctx, { grouped, extraGroups, summaries }));
    const local = { ...ctx, changed: (key) => (key !== undefined && deps.has(key) ? (ctx.changed(), rerender()) : ctx.changed()) };

    if (!grouped) {
        for (const field of visible) container.append(renderField(field, values, `${path}.${field.key}`, local));
        return container;
    }

    for (const [group, label] of Object.entries(ctx.fieldGroups)) {
        const inGroup = visible.filter((field) => (field.group ?? 'content') === group);
        const extra = extraGroups[group] ?? null;
        if (inGroup.length === 0 && !extra) continue;
        const errors = inGroup.reduce((count, field) => count + ctx.countErrors(`${path}.${field.key}`), 0);
        const key = `${ctx.blockKey}:${group}`;
        const open = errors > 0 || (ctx.openGroups.has(key) ? ctx.openGroups.get(key) : group === 'content' || group === 'products');
        const details = h('details', { class: 'group border-t border-line first:border-t-0', open, dataset: { fieldGroup: group } },
            h('summary', { class: 'flex cursor-pointer list-none items-center gap-2 py-4 [&::-webkit-details-marker]:hidden' },
                h('span', { class: 'text-fg-secondary transition group-open:rotate-90 rtl:-scale-x-100' }, icon('chevron', 18)),
                h('span', { class: 'flex-1 text-[17px] font-semibold' }, label),
                errors ? h('span', { class: 'rounded-md bg-danger-subtle px-1.5 text-xs font-medium text-fg-danger' }, ctx.t(errors === 1 ? ':n error' : ':n errors', { n: errors })) : summaries[group] ? h('span', { class: 'text-xs text-fg-secondary group-open:hidden' }, summaries[group]) : null),
            h('div', { class: 'flex flex-col gap-5 pb-6' }, inGroup.map((field) => renderField(field, values, `${path}.${field.key}`, local)), extra));
        details.addEventListener('toggle', () => ctx.openGroups.set(key, details.open));
        container.append(details);
    }
    return container;
}

let counter = 0;
const nextId = () => `pb-field-${++counter}`;

function renderField(field, values, path, ctx) {
    const renderer = RENDERERS[field.type] ?? (field.type.endsWith('_picker') ? renderPicker : renderText);
    return renderer(field, values, path, ctx);
}

/** Label row: label, required star, and an "EN · AR" tag for bilingual fields. */
function labelRow(field, ctx, forId = null) {
    return h('div', { class: 'flex items-center justify-between gap-2' },
        h('label', { class: 'flex items-center gap-1 text-sm font-medium text-fg', for: forId },
            field.label, field.required ? h('span', { class: 'text-fg-danger', title: ctx.t('Needed to publish') }, '*') : null),
        field.translatable && ctx.lang === 'both' ? h('span', { class: 'rounded border border-line px-1.5 text-[11px] font-medium text-fg-secondary', dir: 'ltr' }, 'EN · AR') : null);
}

function fieldShell(field, path, ctx, control, { forId = null, footer = null } = {}) {
    const error = ctx.errorFor(path);
    return h('div', { class: 'flex flex-col gap-1.5', dataset: { fieldPath: path } },
        labelRow(field, ctx, forId),
        control,
        error ? h('p', { class: 'text-xs text-fg-danger', role: 'alert' }, error) : null,
        !error && (field.help || footer) ? h('div', { class: 'flex items-start justify-between gap-2' }, field.help ? h('p', { class: 'text-xs text-fg-secondary' }, field.help) : h('span'), footer) : null);
}

/** The bilingual value object of a field (created on demand). */
function translation(values, key) {
    if (!isTranslation(values[key])) values[key] = filled(values[key]) ? { en: values[key] } : {};
    return values[key];
}

function setSide(values, key, locale, value) {
    const t = translation(values, key);
    if (filled(value)) t[locale] = value;
    else delete t[locale];
    if (Object.keys(t).length === 0) delete values[key];
}

/** Languages shown for the current panel language switch. */
const sides = (ctx) => (ctx.lang === 'both' ? LOCALES : [ctx.lang]);

const localeBadge = (locale) => h('span', { class: 'pointer-events-none absolute start-2 top-1/2 -translate-y-1/2 rounded border border-line bg-page px-1 text-[11px] font-medium text-fg-secondary' }, locale === 'ar' ? 'ع' : 'EN');

/** EN | AR inputs (Figma 243:237): EN left, AR right (RTL), per-language errors, "Copy EN → AR". */
function bilingual(field, values, path, ctx, build) {
    if (!field.translatable) {
        const id = nextId();
        return fieldShell(field, path, ctx, build(field.key, id, null, (v) => setPlain(values, field.key, v), values[field.key]), { forId: id });
    }
    const t = translation(values, field.key);
    const ids = {};
    const box = h('div', { class: `grid gap-2 ${sides(ctx).length === 2 ? 'grid-cols-2' : ''}`, dir: 'ltr' });
    box.replaceChildren(...sides(ctx).map((locale) => {
        ids[locale] = nextId();
        const error = ctx.errorFor(`${path}.${locale}`);
        const otherFilled = filled(t[locale === 'en' ? 'ar' : 'en']);
        return h('div', { class: 'flex min-w-0 flex-col gap-1' },
            build(`${field.key}.${locale}`, ids[locale], locale, (v) => { setSide(values, field.key, locale, v); ctx.changed(field.key); }, t[locale], error || (otherFilled && !filled(t[locale]))),
            error ? h('p', { class: 'text-xs text-fg-danger' }, error) : null,
            !error && otherFilled && !filled(t[locale]) ? h('p', { class: 'text-xs text-fg-danger' }, locale === 'ar' ? ctx.t('Arabic :field is missing.', { field: field.label.toLowerCase() }) : ctx.t('English :field is missing.', { field: field.label.toLowerCase() })) : null);
    }));
    const copy = sides(ctx).includes('ar') && ['text', 'textarea', 'rich_text', 'tags'].includes(field.type)
        ? h('button', { type: 'button', class: 'shrink-0 text-xs font-medium text-fg-link hover:underline', onclick: () => { if (filled(t.en)) { t.ar = clone(t.en); ctx.changed(field.key); ctx.rerenderForm(); } } }, ctx.t('Copy EN → AR'))
        : null;
    return fieldShell(field, path, ctx, box, { forId: ids[sides(ctx)[0]], footer: copy });
}

function setPlain(values, key, value) {
    if (filled(value)) values[key] = value;
    else delete values[key];
}

function textInput(type) {
    return (key, id, locale, onValue, value, invalid) => {
        const attrs = {
            id, class: `${inputClass} ${locale && type !== 'textarea' ? 'ps-9' : ''} ${invalid ? 'border-line-danger' : ''} ${locale === 'ar' ? 'font-arabic' : ''}`,
            dir: locale === 'ar' ? 'rtl' : locale === 'en' ? 'ltr' : null, lang: locale,
            oninput: (e) => onValue(e.target.value),
        };
        const input = type === 'textarea'
            ? h('textarea', { ...attrs, rows: 3, class: `${attrs.class} h-auto py-2` }, value ?? '')
            : h('input', { ...attrs, type: 'text', value: value ?? '' });
        return h('div', { class: 'relative' }, input, locale && type !== 'textarea' ? localeBadge(locale) : null);
    };
}

function renderText(field, values, path, ctx) {
    return bilingual(field, values, path, ctx, textInput('text'));
}

function renderTextarea(field, values, path, ctx) {
    return bilingual(field, values, path, ctx, textInput('textarea'));
}

function renderRichText(field, values, path, ctx) {
    return bilingual(field, values, path, ctx, (key, id, locale, onValue, value) => {
        const area = h('div', {
            id, contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', dir: locale === 'ar' ? 'rtl' : 'ltr', lang: locale,
            class: `min-h-32 max-h-80 overflow-y-auto rounded-b-lg border border-line bg-surface px-3 py-2 text-sm focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30 [&_a]:text-fg-link [&_a]:underline [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:text-base [&_h3]:font-semibold [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:list-decimal [&_ol]:ps-5 ${locale === 'ar' ? 'font-arabic' : ''}`,
            html: value ?? '',
            oninput: () => onValue(area.innerHTML === '<br>' ? '' : area.innerHTML),
        });
        const command = (name, arg = null) => (e) => {
            e.preventDefault();
            area.focus();
            if (name === 'createLink') {
                const url = window.prompt(ctx.t('Link address (https://… or /path)'));
                if (!url) return;
                arg = url;
            }
            document.execCommand(name, false, arg);
            onValue(area.innerHTML);
        };
        const tool = (content, name, arg, title) => h('button', { type: 'button', class: 'grid h-7 min-w-7 place-items-center rounded px-1 text-xs font-semibold text-fg-secondary hover:bg-subtle hover:text-fg', title, 'aria-label': title, onmousedown: command(name, arg) }, content);
        return h('div', { class: 'flex flex-col' },
            h('div', { class: 'flex flex-wrap items-center gap-0.5 rounded-t-lg border border-b-0 border-line bg-page px-1 py-1' },
                locale ? h('span', { class: 'me-1 rounded border border-line bg-surface px-1 text-[11px] text-fg-secondary' }, locale === 'ar' ? 'ع' : 'EN') : null,
                tool('¶', 'formatBlock', 'p', ctx.t('Paragraph')), tool('H2', 'formatBlock', 'h2', 'H2'), tool('H3', 'formatBlock', 'h3', 'H3'),
                tool(icon('bold', 14), 'bold', null, ctx.t('Bold')), tool(icon('italic', 14), 'italic', null, ctx.t('Italic')),
                tool(icon('list', 14), 'insertUnorderedList', null, ctx.t('Bullet list')), tool(icon('link', 14), 'createLink', null, ctx.t('Link'))),
            area);
    });
}

function renderNumber(field, values, path, ctx) {
    const id = nextId();
    const unit = field.unit === 'currency' ? ctx.currency : field.unit;
    const error = ctx.errorFor(path);
    const input = h('input', {
        id, type: 'number', class: 'h-10 min-w-0 flex-1 border-0 bg-transparent px-3 text-sm focus:outline-none', value: values[field.key] ?? '', min: field.min ?? null, max: field.max ?? null, step: 'any', dir: 'ltr',
        placeholder: field.default !== undefined ? String(field.default) : '',
        oninput: (e) => { setPlain(values, field.key, e.target.value === '' ? '' : Number(e.target.value)); ctx.changed(); },
        onchange: () => ctx.changed(field.key),
    });
    const hint = field.min !== undefined && field.min !== null && field.max !== undefined && field.max !== null ? ctx.t('Between :min and :max.', { min: field.min, max: field.max }) : null;
    return fieldShell({ ...field, help: field.help ?? hint }, path, ctx, h('div', { class: `flex items-stretch overflow-hidden rounded-lg border bg-surface focus-within:border-line-focus focus-within:ring-2 focus-within:ring-brand/30 ${error ? 'border-line-danger' : 'border-line'}` },
        input, unit ? h('span', { class: 'flex items-center border-s border-line bg-page px-3 text-sm text-fg-secondary' }, unit) : null), { forId: id });
}

/** 2–4 options → segmented control (243:311); more → select (243:301). */
function renderSelect(field, values, path, ctx) {
    const id = nextId();
    const current = values[field.key] === undefined || values[field.key] === null ? '' : String(values[field.key]);
    const options = [...(field.options ?? [])];
    const hasDefault = field.default !== undefined && options.some((o) => o.value === String(field.default));
    if (!options.some((o) => o.value === '') && !hasDefault) options.unshift({ value: '', label: ctx.t('Default') });
    if (current !== '' && !options.some((o) => o.value === current)) options.push({ value: current, label: `${current} (${ctx.t('saved value')})` });
    const effective = current === '' && hasDefault ? String(field.default) : current;
    if (options.length <= 4) {
        const group = h('div', { id, class: 'grid gap-1 rounded-lg bg-page p-1', style: { gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }, role: 'radiogroup' },
            options.map((option) => {
                const active = option.value === effective;
                return h('button', {
                    type: 'button', role: 'radio', 'aria-checked': String(active), title: option.label,
                    class: `truncate rounded-md px-2 py-1.5 text-sm ${active ? 'bg-surface font-medium text-fg shadow-xs ring-1 ring-line' : 'text-fg-secondary hover:text-fg'}`,
                    onclick: () => { setPlain(values, field.key, option.value); ctx.changed(field.key); ctx.rerenderForm(); },
                }, option.label);
            }));
        return fieldShell(field, path, ctx, group);
    }
    return fieldShell(field, path, ctx, h('div', { class: 'relative' },
        h('select', { id, class: `${inputClass} appearance-none pe-9`, onchange: (e) => { setPlain(values, field.key, e.target.value); ctx.changed(field.key); } },
            options.map((option) => h('option', { value: option.value, selected: option.value === effective }, option.label))),
        h('span', { class: 'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-secondary' }, icon('down', 16))), { forId: id });
}

/** Toggle (243:321): label + description, switch at the end. */
function renderToggle(field, values, path, ctx) {
    const id = nextId();
    const checked = values[field.key] === undefined ? Boolean(field.default) : Boolean(values[field.key]);
    return h('div', { class: 'flex items-start justify-between gap-4', dataset: { fieldPath: path } },
        h('label', { for: id, class: 'flex cursor-pointer flex-col' }, h('span', { class: 'text-sm font-medium' }, field.label), field.help ? h('span', { class: 'text-xs text-fg-secondary' }, field.help) : null),
        switchControl(id, checked, (on) => { values[field.key] = on; ctx.changed(field.key); }));
}

export function switchControl(id, checked, onChange) {
    return h('label', { class: 'relative mt-0.5 inline-flex shrink-0 cursor-pointer' },
        h('input', { id, type: 'checkbox', role: 'switch', class: 'peer sr-only', checked, onchange: (e) => onChange(e.target.checked) }),
        h('span', { class: 'h-6 w-10 rounded-full bg-line-strong transition peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-line-focus' }),
        h('span', { class: 'pointer-events-none absolute start-0.5 top-0.5 size-5 rounded-full bg-surface shadow transition peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4' }));
}

function renderCheckboxGroup(field, values, path, ctx) {
    const selected = new Set(Array.isArray(values[field.key]) ? values[field.key] : []);
    return fieldShell(field, path, ctx, h('div', { class: 'flex flex-wrap gap-2' }, (field.options ?? []).map((option) =>
        h('label', { class: 'flex h-9 items-center gap-2 rounded-lg border border-line px-3 text-sm has-[:checked]:border-line-brand has-[:checked]:bg-brand-subtle' }, h('input', {
            type: 'checkbox', class: 'size-4 accent-brand', checked: selected.has(option.value),
            onchange: (e) => { e.target.checked ? selected.add(option.value) : selected.delete(option.value); values[field.key] = [...selected]; ctx.changed(field.key); },
        }), option.label))));
}

/** Colour (243:344): token presets first, hex second. */
const TOKENS = [
    ['var(--color-brand-subtle)', '#fff8e6'], ['var(--color-brand)', '#f9bb25'], ['var(--color-success-subtle)', '#ecfdf3'], ['var(--color-info-subtle)', '#eff6ff'],
    ['var(--color-warning-subtle)', '#fff6ed'], ['var(--color-danger-subtle)', '#fef3f2'], ['var(--color-subtle)', '#efeff1'], ['var(--color-surface)', '#ffffff'],
];

function renderColor(field, values, path, ctx) {
    const id = nextId();
    const value = values[field.key] ?? '';
    const set = (next) => { setPlain(values, field.key, next); ctx.changed(field.key); ctx.rerenderForm(); };
    return fieldShell(field, path, ctx, h('div', { class: 'flex flex-col gap-2' },
        h('div', { class: 'flex flex-wrap gap-1.5' },
            h('button', { type: 'button', class: `grid size-8 place-items-center rounded-lg border text-fg-muted ${value === '' ? 'border-line-focus ring-2 ring-brand/30' : 'border-line'}`, title: ctx.t('None'), onclick: () => set('') }, icon('x', 14)),
            TOKENS.map(([token, swatch]) => h('button', { type: 'button', title: token, class: `size-8 rounded-lg border ${value === token ? 'border-line-focus ring-2 ring-brand/30' : 'border-line'}`, style: { background: swatch }, onclick: () => set(token) }))),
        h('div', { class: 'flex items-center gap-2' },
            h('input', { type: 'color', class: 'size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface p-1', value: /^#[0-9a-f]{6}$/i.test(value) ? value : '#ffffff', title: ctx.t('Pick a colour'), onchange: (e) => set(e.target.value) }),
            h('input', { id, type: 'text', class: `${inputClass} font-mono`, dir: 'ltr', value, placeholder: '#RRGGBB', onchange: (e) => set(e.target.value.trim()) }))), { forId: id });
}

/** Wall-clock time in the country → the Oman equivalent ("08:00 Oman"). */
function omanLabel(local, ctx) {
    if (!local) return '';
    try {
        const [date, time] = local.split('T');
        const [y, m, d] = date.split('-').map(Number);
        const [hh, mm] = time.split(':').map(Number);
        const guess = Date.UTC(y, m - 1, d, hh, mm);
        const offset = (tz, at) => {
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).formatToParts(new Date(at)).map((p) => [p.type, p.value]));
            return Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute) - at;
        };
        const utc = guess - offset(ctx.country.timezone, guess);
        return `${new Intl.DateTimeFormat('en-GB', { timeZone: ctx.adminTimezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(utc))} ${ctx.t('Oman')}`;
    } catch {
        return '';
    }
}

function datetimeInput(value, onValue, ctx, id = nextId()) {
    const chip = h('span', { class: 'pointer-events-none absolute end-10 top-1/2 -translate-y-1/2 rounded bg-warning-subtle px-1.5 text-[11px] font-medium text-fg-warning' }, omanLabel(value, ctx));
    chip.hidden = !value;
    const input = h('input', { id, type: 'datetime-local', class: `${inputClass} pe-32`, dir: 'ltr', value: value ?? '', oninput: (e) => { onValue(e.target.value); chip.textContent = omanLabel(e.target.value, ctx); chip.hidden = !e.target.value; ctx.changed(); } });
    return h('div', { class: 'relative', dir: 'ltr' }, input, chip);
}

function renderDatetime(field, values, path, ctx) {
    const id = nextId();
    return fieldShell({ ...field, help: field.help ?? ctx.t('Times in :country (:tz).', { country: ctx.country.name, tz: ctx.country.timezone }) }, path, ctx, datetimeInput(values[field.key], (v) => setPlain(values, field.key, v), ctx, id), { forId: id });
}

/** Schedule (245:21): toggle + starts / ends in country time with the Oman chip. */
function renderDatetimeRange(field, values, path, ctx) {
    const range = (values[field.key] = values[field.key] && typeof values[field.key] === 'object' && !Array.isArray(values[field.key]) ? values[field.key] : {});
    const on = filled(range.starts_at) || filled(range.ends_at) || ctx.isOpen(range);
    const id = nextId();
    const body = h('div', { class: 'flex flex-col gap-3' },
        h('label', { class: 'flex flex-col gap-1.5 text-sm font-medium' }, ctx.t('Starts'), datetimeInput(range.starts_at, (v) => { range.starts_at = v; }, ctx)),
        ctx.errorFor(`${path}.starts_at`) ? h('p', { class: 'text-xs text-fg-danger' }, ctx.errorFor(`${path}.starts_at`)) : null,
        h('label', { class: 'flex flex-col gap-1.5 text-sm font-medium' }, ctx.t('Ends'), datetimeInput(range.ends_at, (v) => { range.ends_at = v; }, ctx)),
        ctx.errorFor(`${path}.ends_at`) ? h('p', { class: 'text-xs text-fg-danger' }, ctx.errorFor(`${path}.ends_at`)) : null,
        h('p', { class: 'text-xs text-fg-secondary' }, ctx.t('Times in :country (:tz).', { country: ctx.country.name, tz: ctx.country.timezone })));
    body.hidden = !on;
    return h('div', { class: 'flex flex-col gap-3', dataset: { fieldPath: path } },
        h('div', { class: 'flex items-start justify-between gap-4' },
            h('label', { for: id, class: 'flex cursor-pointer flex-col' }, h('span', { class: 'text-sm font-medium' }, ctx.t('Schedule this section')), field.help ? h('span', { class: 'text-xs text-fg-secondary' }, field.help) : null),
            switchControl(id, on, (checked) => { ctx.setOpen(range, checked); if (!checked) { delete range.starts_at; delete range.ends_at; ctx.changed(); } body.hidden = !checked; })),
        body);
}

/** One image slot (245:112): drop zone or preview with Replace / Remove. */
function imageSlot(url, onValue, ctx, { label = null } = {}) {
    const status = h('span', { class: 'text-xs text-fg-secondary', role: 'status' });
    const upload = async (chosen) => {
        if (!chosen) return;
        status.textContent = ctx.t('Uploading…');
        const form = new FormData();
        form.append('image', chosen);
        const result = await requestJson(ctx.urls.upload, { method: 'POST', form });
        if (result.ok && result.data?.url) {
            onValue(result.data.url);
            ctx.rerenderForm();
        } else {
            status.textContent = result.data?.errors?.image?.[0] ?? result.data?.message ?? ctx.t('Upload failed.');
        }
    };
    const file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/gif,image/avif', class: 'sr-only', onchange: (e) => upload(e.target.files?.[0]) });
    const urlInput = h('input', { type: 'url', class: `${inputClass} h-8 text-xs`, dir: 'ltr', value: url ?? '', placeholder: 'https://… / /img/…', 'aria-label': ctx.t('Image address'), onchange: (e) => { onValue(e.target.value.trim()); ctx.rerenderForm(); } });
    let box;
    if (url) {
        const img = h('img', { src: url, alt: '', class: 'max-h-full max-w-full object-contain', loading: 'lazy' });
        img.addEventListener('error', () => img.replaceWith(h('span', { class: 'flex items-center gap-1 text-xs text-fg-danger' }, icon('alert', 14), ctx.t('Image not found'))));
        box = h('div', { class: 'flex flex-col gap-2 rounded-lg border border-line p-2' },
            h('div', { class: 'grid h-28 place-items-center overflow-hidden rounded-md bg-subtle' }, img),
            h('div', { class: 'flex flex-wrap items-center gap-1' },
                h('label', { class: `${buttonClass.ghost} cursor-pointer` }, icon('upload', 14), ctx.t('Replace'), file),
                h('button', { type: 'button', class: `${buttonClass.ghost} text-fg-danger`, onclick: () => { onValue(''); ctx.rerenderForm(); } }, icon('trash', 14), ctx.t('Remove')), status),
            urlInput);
    } else {
        const zone = h('label', { class: 'flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-line bg-page px-3 py-5 text-center hover:border-line-focus' },
            icon('upload', 20, 'text-fg-secondary'),
            h('span', { class: 'text-sm font-medium' }, ctx.t('Drop an image or browse')),
            h('span', { class: 'text-xs text-fg-secondary' }, ctx.t('PNG, JPG, WebP · up to 5 MB')), file, status);
        zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('border-line-focus'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('border-line-focus'));
        zone.addEventListener('drop', (e) => { e.preventDefault(); upload(e.dataTransfer.files?.[0]); });
        box = h('div', { class: 'flex flex-col gap-2' }, zone, urlInput);
    }
    return h('div', { class: 'flex min-w-0 flex-col gap-1' }, label ? h('span', { class: 'text-xs font-medium text-fg-secondary' }, label) : null, box);
}

/**
 * Image upload, bilingual (paired variant of 245:112): EN slot, "Use the
 * same image for Arabic" (on by default; AR falls back to EN), AR slot,
 * alt text EN/AR.
 */
function renderImage(field, values, path, ctx) {
    const t = translation(values, field.key);
    const same = !filled(t.ar) && !ctx.isOpen(t);
    const show = sides(ctx);
    const slots = [];
    if (show.includes('en') || same) {
        slots.push(imageSlot(t.en, (v) => { setSide(values, field.key, 'en', v); ctx.changed(field.key); }, ctx, { label: show.includes('ar') ? (same ? ctx.t('English & Arabic') : 'English') : null }));
    }
    if (show.includes('ar') && !same) {
        slots.push(imageSlot(t.ar, (v) => { setSide(values, field.key, 'ar', v); ctx.changed(field.key); }, ctx, { label: 'العربية' }));
    }
    const sameId = nextId();
    const sameToggle = show.includes('ar') ? h('div', { class: 'flex items-center justify-between gap-3 rounded-lg bg-page px-3 py-2' },
        h('label', { for: sameId, class: 'text-sm' }, ctx.t('Use the same image for Arabic')),
        switchControl(sameId, same, (checked) => {
            if (checked) { delete t.ar; ctx.setOpen(t, false); } else { ctx.setOpen(t, true); }
            ctx.changed(field.key);
            ctx.rerenderForm();
        })) : null;
    const altKey = field.alt;
    const alt = altKey ? (() => {
        const at = translation(values, altKey);
        return h('div', { class: `grid gap-2 ${show.length === 2 ? 'grid-cols-2' : ''}`, dir: 'ltr' }, show.map((locale) => h('div', { class: 'relative' }, h('input', {
            type: 'text', class: `${inputClass} ps-9 ${locale === 'ar' ? 'font-arabic' : ''}`, dir: locale === 'ar' ? 'rtl' : 'ltr', lang: locale, value: at[locale] ?? '', placeholder: ctx.t('Alt text'),
            'aria-label': `${ctx.t('Alt text')} ${locale.toUpperCase()}`, oninput: (e) => { setSide(values, altKey, locale, e.target.value); ctx.changed(); },
        }), localeBadge(locale))));
    })() : null;
    const errors = ['en', 'ar'].map((l) => ctx.errorFor(`${path}.${l}`)).filter(Boolean);
    return h('div', { class: 'flex flex-col gap-2', dataset: { fieldPath: path } },
        labelRow(field, ctx),
        h('div', { class: `grid gap-2 ${slots.length === 2 ? 'grid-cols-2' : ''}` }, slots),
        sameToggle,
        alt,
        errors.length ? h('p', { class: 'text-xs text-fg-danger' }, errors[0]) : field.help ? h('p', { class: 'text-xs text-fg-secondary' }, field.help) : null);
}

const LINK_KINDS = [['page', 'Page'], ['category', 'Category'], ['product', 'Product'], ['url', 'URL']];

function linkKind(value) {
    if (!value) return 'page';
    if (/^https?:\/\//i.test(value) || value.startsWith('#') || value.startsWith('/brands/') || value.startsWith('/flash-sale/')) return 'url';
    if (value.startsWith('/products/')) return 'product';
    return value.split('/').filter(Boolean).length > 1 || value.includes('?') ? 'category' : 'page';
}

/** One link (245:227): Page / Category / Product / URL with live search and the resolved address. */
function linkEditor(initial, onValue, ctx, { locale = null } = {}) {
    const wrap = h('div', { class: 'flex flex-col gap-2' });
    let value = initial ?? '';
    let kind = linkKind(value);
    const paint = () => {
        const tabs = h('div', { class: 'grid grid-cols-4 gap-1 rounded-lg bg-page p-1' }, LINK_KINDS.map(([key, label]) => h('button', {
            type: 'button', class: `rounded-md py-1.5 text-sm ${kind === key ? 'bg-surface font-medium shadow-xs ring-1 ring-line' : 'text-fg-secondary hover:text-fg'}`,
            onclick: () => { kind = key; paint(); },
        }, ctx.t(label))));
        let control;
        if (kind === 'url') {
            control = h('div', { class: 'relative' }, h('input', { type: 'text', class: `${inputClass} ${locale ? 'ps-9' : ''}`, dir: 'ltr', value, placeholder: 'https://… · /path · #section', oninput: (e) => { value = e.target.value.trim(); onValue(value); } }), locale ? localeBadge(locale) : null);
        } else {
            const list = h('div', { class: 'absolute inset-x-0 top-full z-30 mt-1 hidden max-h-72 overflow-y-auto rounded-lg border border-line bg-surface py-1 shadow-lg' });
            const run = debounce(async (q) => {
                const rows = await ctx.picker('link', { q, type: kind });
                list.replaceChildren(...(rows.length ? rows.map((row) => resultRow(row, () => { value = row.value; onValue(value); list.classList.add('hidden'); paint(); }, row.value === value)) : [h('p', { class: 'px-3 py-2 text-sm text-fg-secondary' }, ctx.t('Nothing found.'))]));
                list.classList.remove('hidden');
            }, 250);
            const search = h('input', { type: 'search', class: `${inputClass} ps-9 pe-9`, placeholder: value || ctx.t('Search…'), autocomplete: 'off', oninput: (e) => run(e.target.value), onfocus: (e) => run(e.target.value) });
            search.addEventListener('blur', () => setTimeout(() => list.classList.add('hidden'), 150));
            control = h('div', { class: 'relative' }, h('span', { class: 'pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-fg-muted' }, icon('link', 15)), search,
                h('span', { class: 'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-secondary' }, icon('down', 16)), list);
        }
        const resolved = value ? ctx.resolveLink(value, locale) : '';
        wrap.replaceChildren(tabs, control, value ? h('div', { class: 'flex items-center justify-between gap-2' },
            h('a', { href: resolved, target: '_blank', rel: 'noopener', class: 'flex min-w-0 items-center gap-1 text-xs text-fg-link hover:underline', dir: 'ltr' }, h('span', { class: 'truncate' }, resolved.replace(/^https?:\/\/[^/]+/, '')), icon('external', 12)),
            h('button', { type: 'button', class: 'text-xs text-fg-secondary hover:text-fg-danger', onclick: () => { value = ''; onValue(''); paint(); } }, ctx.t('Clear'))) : null);
    };
    paint();
    return wrap;
}

/** Bilingual link: EN link + "Same link for both languages" toggle + AR link. */
function renderLink(field, values, path, ctx) {
    const t = translation(values, field.key);
    const same = !filled(t.ar) && !ctx.isOpen(t);
    const show = sides(ctx);
    const parts = [];
    if (show.includes('en') || same) parts.push(h('div', { class: 'flex flex-col gap-1' }, show.includes('ar') && !same ? h('span', { class: 'text-xs font-medium text-fg-secondary' }, 'English') : null, linkEditor(t.en, (v) => { setSide(values, field.key, 'en', v); ctx.changed(field.key); }, ctx, { locale: 'en' })));
    if (show.includes('ar') && !same) parts.push(h('div', { class: 'flex flex-col gap-1' }, h('span', { class: 'text-xs font-medium text-fg-secondary' }, 'العربية'), linkEditor(t.ar, (v) => { setSide(values, field.key, 'ar', v); ctx.changed(field.key); }, ctx, { locale: 'ar' })));
    const sameId = nextId();
    const toggle = show.includes('ar') ? h('div', { class: 'flex items-center justify-between gap-3' }, h('label', { for: sameId, class: 'text-xs text-fg-secondary' }, ctx.t('Same link for both languages')),
        switchControl(sameId, same, (checked) => { if (checked) { delete t.ar; ctx.setOpen(t, false); } else ctx.setOpen(t, true); ctx.changed(field.key); ctx.rerenderForm(); })) : null;
    const error = ctx.errorFor(`${path}.en`) ?? ctx.errorFor(`${path}.ar`);
    return h('div', { class: 'flex flex-col gap-1.5', dataset: { fieldPath: path } }, labelRow({ ...field, translatable: false }, ctx), ...parts, toggle,
        error ? h('p', { class: 'text-xs text-fg-danger' }, error) : field.help ? h('p', { class: 'text-xs text-fg-secondary' }, field.help) : null);
}

function renderTags(field, values, path, ctx) {
    return bilingual(field, values, path, ctx, (key, id, locale, onValue, value) => {
        let tags = Array.isArray(value) ? [...value] : typeof value === 'string' && value ? value.split(/[\n,]/).map((x) => x.trim()).filter(Boolean) : [];
        const box = h('div', { class: 'flex min-h-10 flex-wrap items-center gap-1 rounded-lg border border-line bg-surface px-2 py-1 focus-within:border-line-focus focus-within:ring-2 focus-within:ring-brand/30', dir: locale === 'ar' ? 'rtl' : 'ltr' });
        const entry = h('input', { id, type: 'text', class: 'min-w-20 flex-1 border-0 bg-transparent p-1 text-sm focus:outline-none', placeholder: ctx.t('Type and press Enter') });
        const paint = () => {
            box.replaceChildren(...tags.map((tag, i) => h('span', { class: 'inline-flex items-center gap-1 rounded-md bg-subtle px-2 py-0.5 text-xs' }, tag,
                h('button', { type: 'button', class: 'text-fg-secondary hover:text-fg-danger', 'aria-label': `${ctx.t('Remove')} ${tag}`, onclick: () => { tags.splice(i, 1); onValue([...tags]); paint(); } }, '×'))), entry);
        };
        entry.addEventListener('keydown', (e) => {
            if ((e.key === 'Enter' || e.key === ',') && entry.value.trim()) {
                e.preventDefault();
                tags = [...tags, entry.value.trim()];
                entry.value = '';
                onValue([...tags]);
                paint();
                entry.focus();
            } else if (e.key === 'Backspace' && entry.value === '' && tags.length) {
                tags = tags.slice(0, -1);
                onValue([...tags]);
                paint();
                entry.focus();
            }
        });
        paint();
        return box;
    });
}

function resultRow(row, onPick, selected = false) {
    return h('button', { type: 'button', class: `flex w-full items-center gap-3 px-3 py-2 text-start text-sm hover:bg-page focus:bg-page focus:outline-none ${selected ? 'bg-brand-subtle' : ''}`, onmousedown: (e) => { e.preventDefault(); onPick(); } },
        row.image ? h('img', { src: row.image, alt: '', class: 'size-9 shrink-0 rounded-md border border-line bg-page object-contain', loading: 'lazy' }) : null,
        h('span', { class: 'flex min-w-0 flex-1 flex-col' }, h('span', { class: 'truncate' }, row.label), row.sub ? h('span', { class: 'truncate text-xs text-fg-secondary' }, row.sub) : null),
        selected ? icon('check', 16) : null);
}

/** Product picker (245:113) and entity pickers (245:195): chips / ordered list + live search. */
function renderPicker(field, values, path, ctx) {
    const kind = field.type.replace(/_picker$/, '');
    const id = nextId();
    const error = ctx.errorFor(path);
    const productList = kind === 'product';
    const selectedBox = h('div', { class: 'flex flex-col gap-1.5' });
    const chips = h('div', { class: 'contents' });
    const list = h('div', { class: 'absolute inset-x-0 top-full z-30 mt-1 hidden max-h-80 overflow-y-auto rounded-lg border border-line bg-surface py-1 shadow-lg', role: 'listbox' });
    const counterLabel = h('span', { class: 'text-xs text-fg-secondary' });
    const subLabel = h('span', { class: 'text-xs text-fg-secondary' });
    const current = () => (field.multiple ? (Array.isArray(values[field.key]) ? values[field.key].map(String) : []) : filled(values[field.key]) ? [String(values[field.key])] : []);
    const search = h('input', { id, type: 'search', class: 'h-8 min-w-24 flex-1 border-0 bg-transparent px-1 text-sm focus:outline-none', placeholder: ctx.t('Search…'), autocomplete: 'off' });

    const paint = async () => {
        const picked = current();
        const known = await ctx.labels(kind, picked);
        const rows = picked.map((value) => known.get(value) ?? { value, label: value, sub: ctx.t('Not found'), image: null });
        if (productList) {
            selectedBox.replaceChildren(...rows.map((row, i) => {
                const item = h('div', { class: 'flex items-center gap-2 rounded-lg border border-line bg-surface px-2 py-1.5 text-sm', draggable: 'true' },
                    h('span', { class: 'cursor-grab text-fg-muted' }, icon('grip', 14)),
                    row.image ? h('img', { src: row.image, alt: '', class: 'size-10 rounded-md border border-line bg-page object-contain' }) : h('span', { class: 'size-10 rounded-md bg-subtle' }),
                    h('span', { class: 'flex min-w-0 flex-1 flex-col' }, h('span', { class: 'truncate' }, row.label), row.sub ? h('span', { class: 'truncate text-xs text-fg-secondary' }, row.sub) : null),
                    h('button', { type: 'button', class: buttonClass.icon, title: ctx.t('Remove'), onclick: () => remove(i) }, icon('x', 14)));
                item.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/x-pick', String(i)); e.dataTransfer.effectAllowed = 'move'; });
                item.addEventListener('dragover', (e) => { if (e.dataTransfer.types.includes('text/x-pick')) { e.preventDefault(); item.classList.add('ring-2', 'ring-brand'); } });
                item.addEventListener('dragleave', () => item.classList.remove('ring-2', 'ring-brand'));
                item.addEventListener('drop', (e) => { e.preventDefault(); item.classList.remove('ring-2', 'ring-brand'); move(Number(e.dataTransfer.getData('text/x-pick')), i); });
                return item;
            }));
            counterLabel.textContent = `${picked.length}${field.max ? ` / ${field.max}` : ''} ${ctx.t('selected')}`;
        } else {
            chips.replaceChildren(...rows.map((row, i) => h('span', { class: 'inline-flex max-w-full items-center gap-1 rounded-md border border-line bg-page py-0.5 ps-2 pe-1 text-sm' },
                h('span', { class: 'truncate' }, row.label),
                h('button', { type: 'button', class: 'grid size-5 place-items-center rounded text-fg-secondary hover:text-fg-danger', 'aria-label': `${ctx.t('Remove')} ${row.label}`, onclick: () => remove(i) }, icon('x', 12)))));
            search.hidden = !field.multiple && picked.length > 0;
            subLabel.textContent = !field.multiple && rows[0]?.sub ? rows[0].sub : '';
        }
    };
    const pick = (row) => {
        if (field.multiple) {
            const picked = current();
            if (picked.includes(String(row.value))) values[field.key] = picked.filter((v) => v !== String(row.value)).map((v) => (productList ? Number(v) : v));
            else if (!field.max || picked.length < field.max) values[field.key] = [...picked, String(row.value)].map((v) => (productList ? Number(v) : v));
        } else {
            values[field.key] = productList ? Number(row.value) : row.value;
            for (const key of field.clears ?? []) delete values[key];
            for (const [target, source] of Object.entries(field.fills ?? {})) {
                if (filled(row[source]) && !filled(pickLocale(values[target], 'en'))) values[target] = clone(row[source]);
            }
        }
        ctx.remember(kind, [row]);
        search.value = '';
        if (!field.multiple) list.classList.add('hidden');
        ctx.changed(field.key);
        paint();
        if (field.fills) ctx.rerenderForm();
    };
    const move = (from, to) => {
        const picked = [...(values[field.key] ?? [])];
        if (Number.isNaN(from) || to < 0 || to >= picked.length) return;
        const [entry] = picked.splice(from, 1);
        picked.splice(to, 0, entry);
        values[field.key] = picked;
        ctx.changed();
        paint();
    };
    const remove = (i) => {
        if (field.multiple) {
            const picked = [...(values[field.key] ?? [])];
            picked.splice(i, 1);
            values[field.key] = picked;
        } else {
            delete values[field.key];
        }
        ctx.changed(field.key);
        paint();
    };
    const run = debounce(async (q) => {
        const rows = await ctx.picker(kind, { q });
        const picked = current();
        list.replaceChildren(...(rows.length ? rows.map((row) => resultRow(row, () => pick(row), picked.includes(String(row.value)))) : [h('p', { class: 'px-3 py-2 text-sm text-fg-secondary' }, ctx.t('Nothing found.'))]));
        list.classList.remove('hidden');
    }, 250);
    search.addEventListener('input', (e) => run(e.target.value));
    search.addEventListener('focus', (e) => run(e.target.value));
    search.addEventListener('blur', () => setTimeout(() => list.classList.add('hidden'), 150));
    search.addEventListener('keydown', (e) => { if (e.key === 'Escape') list.classList.add('hidden'); });
    paint();
    const searchBox = h('div', { class: 'relative' },
        h('div', { class: `flex min-h-10 flex-wrap items-center gap-1 rounded-lg border bg-surface px-2 py-1 focus-within:border-line-focus focus-within:ring-2 focus-within:ring-brand/30 ${error ? 'border-line-danger' : 'border-line'}` },
            productList ? h('span', { class: 'text-fg-muted' }, icon('search', 15)) : null, chips, search),
        list);
    return fieldShell(field, path, ctx, h('div', { class: 'flex flex-col gap-2' }, searchBox, productList ? selectedBox : null, productList ? counterLabel : subLabel), { forId: id });
}

function renderGroup(field, values, path, ctx) {
    const inner = (values[field.key] = values[field.key] && typeof values[field.key] === 'object' && !Array.isArray(values[field.key]) ? values[field.key] : {});
    return h('fieldset', { class: 'flex flex-col gap-4 rounded-xl border border-line p-3', dataset: { fieldPath: path } },
        h('legend', { class: 'px-1 text-sm font-semibold' }, field.label),
        field.help ? h('p', { class: 'text-xs text-fg-secondary' }, field.help) : null,
        renderFields(field.fields ?? [], inner, path, ctx));
}

/** Title of a repeater item from its `item_title` keys (bilingual values in the panel language). */
function itemTitle(field, item, index, ctx) {
    for (const key of field.item_title ?? []) {
        let value = key.split('.').reduce((v, part) => (v && typeof v === 'object' ? v[part] : undefined), item);
        value = pickLocale(value, ctx.lang === 'both' ? ctx.uiLocale : ctx.lang);
        if (filled(value) && typeof value !== 'object') return key.endsWith('type') ? ctx.typeLabel(value) : String(value);
    }
    return `${ctx.t('Item')} ${index + 1}`;
}

/** Repeater (245:257): collapse / expand, drag, duplicate, delete, add, min / max, per-item errors. */
function renderRepeater(field, values, path, ctx) {
    const items = (values[field.key] = Array.isArray(values[field.key]) ? values[field.key] : []);
    const wrap = h('div', { class: 'flex flex-col gap-2', dataset: { fieldPath: path } });
    const move = (from, to) => {
        if (to < 0 || to >= items.length) return;
        const [entry] = items.splice(from, 1);
        items.splice(to, 0, entry);
        ctx.changed();
        ctx.clearErrors(path);
        paint();
    };
    const repeaterItem = (item, index) => {
        if (!item || typeof item !== 'object') return null;
        const open = ctx.isOpen(item);
        const errors = ctx.countErrors(`${path}.${index}`);
        const gaps = translationGaps(field.fields ?? [], item).length;
        const card = h('div', { class: `rounded-lg border ${errors ? 'border-line-danger' : 'border-line'} bg-surface`, draggable: 'true' });
        card.append(h('div', { class: 'flex h-11 items-center gap-1 px-2' },
            h('span', { class: 'cursor-grab px-0.5 text-fg-muted', title: ctx.t('Drag to reorder') }, icon('grip', 15)),
            thumbOf(item, ctx),
            h('button', { type: 'button', class: 'flex min-w-0 flex-1 items-center gap-2 rounded px-1 py-1 text-start text-sm hover:bg-page', 'aria-expanded': String(open), onclick: () => { ctx.setOpen(item, !open); paint(); } },
                h('span', { class: 'truncate' }, itemTitle(field, item, index, ctx)),
                errors ? h('span', { class: 'rounded bg-danger-subtle px-1 text-xs text-fg-danger' }, String(errors)) : null,
                gaps ? h('span', { class: 'shrink-0 rounded bg-warning-subtle px-1 text-[11px] text-fg-warning', title: ctx.t('Missing translations') }, ctx.t(':n to translate', { n: gaps })) : null),
            h('button', { type: 'button', class: buttonClass.icon, title: ctx.t('Duplicate'), disabled: Boolean(field.max && items.length >= field.max), onclick: () => { const copy = clone(item); items.splice(index + 1, 0, copy); ctx.setOpen(copy, true); ctx.changed(); paint(); } }, icon('copy', 14)),
            h('button', { type: 'button', class: `${buttonClass.icon} hover:text-fg-danger`, title: ctx.t('Remove'), onclick: () => { if (window.confirm(ctx.t('Remove this item?'))) { items.splice(index, 1); ctx.changed(); ctx.clearErrors(path); paint(); } } }, icon('trash', 14)),
            h('button', { type: 'button', class: buttonClass.icon, title: open ? ctx.t('Collapse') : ctx.t('Expand'), 'aria-label': open ? ctx.t('Collapse') : ctx.t('Expand'), onclick: () => { ctx.setOpen(item, !open); paint(); } }, icon(open ? 'up' : 'down', 14))));
        if (open) card.append(h('div', { class: 'border-t border-line p-3' }, renderFields(field.fields ?? [], item, `${path}.${index}`, ctx)));
        card.addEventListener('dragstart', (e) => { if (e.target !== card) return; e.stopPropagation(); e.dataTransfer.setData('text/x-item', `${path}|${index}`); e.dataTransfer.effectAllowed = 'move'; });
        card.addEventListener('dragover', (e) => { if (e.dataTransfer.types.includes('text/x-item')) { e.preventDefault(); e.stopPropagation(); card.classList.add('ring-2', 'ring-brand'); } });
        card.addEventListener('dragleave', () => card.classList.remove('ring-2', 'ring-brand'));
        card.addEventListener('drop', (e) => {
            card.classList.remove('ring-2', 'ring-brand');
            const [fromPath, fromIndex] = (e.dataTransfer.getData('text/x-item') || '').split('|');
            if (fromPath !== path) return;
            e.preventDefault();
            e.stopPropagation();
            move(Number(fromIndex), index);
        });
        card.addEventListener('mousedown', (e) => { card.draggable = !e.target.closest('input, textarea, select, [contenteditable]'); });
        card.addEventListener('keydown', (e) => {
            if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && e.target.closest('[aria-expanded]')) { e.preventDefault(); move(index, index + (e.key === 'ArrowUp' ? -1 : 1)); }
        });
        return card;
    };
    const paint = () => {
        const error = ctx.errorFor(path);
        const atMax = field.max && items.length >= field.max;
        wrap.replaceChildren(...[
            h('div', { class: 'flex items-center justify-between gap-2' },
                h('span', { class: 'flex items-center gap-1.5 text-sm font-medium' }, field.label, field.required ? h('span', { class: 'text-fg-danger' }, '*') : null,
                    h('span', { class: 'rounded-md bg-subtle px-1.5 text-xs text-fg-secondary', dir: 'ltr' }, `${items.length}${field.max ? ` / ${field.max}` : ''}`)),
                items.length > 1 ? h('button', { type: 'button', class: 'text-xs text-fg-link hover:underline', onclick: () => { const allOpen = items.every((item) => ctx.isOpen(item)); items.forEach((item) => ctx.setOpen(item, !allOpen)); paint(); } }, ctx.t('Expand / collapse all')) : null),
            error ? h('p', { class: 'text-xs text-fg-danger' }, error) : null,
            ...items.map((item, index) => repeaterItem(item, index)),
            h('button', { type: 'button', class: 'flex h-10 items-center justify-center gap-2 rounded-lg border border-dashed border-line-strong text-sm font-medium text-fg-secondary hover:border-line-focus hover:text-fg disabled:opacity-40', disabled: atMax, onclick: () => { const fresh = defaults(field.fields ?? []); items.push(fresh); ctx.setOpen(fresh, true); ctx.changed(); paint(); } },
                icon('plus', 15), ctx.t('Add item')),
            field.help || field.min ? h('p', { class: `text-xs ${field.min && items.length < field.min ? 'text-fg-warning' : 'text-fg-secondary'}` }, [field.help, field.min ? ctx.t('Min :min', { min: field.min }) : null, field.max ? ctx.t('max :max', { max: field.max }) : null].filter(Boolean).join(' · ')) : null].filter(Boolean));
    };
    paint();
    return wrap;
}

function thumbOf(item, ctx) {
    const url = pickLocale(item.image_d ?? item.image ?? item.logo, ctx.lang === 'both' ? ctx.uiLocale : ctx.lang);
    if (typeof url !== 'string' || !url) return null;
    const img = h('img', { src: url, alt: '', class: 'size-7 shrink-0 rounded border border-line bg-page object-cover', loading: 'lazy' });
    img.addEventListener('error', () => img.remove());
    return img;
}

/** One child block of a container (panel_row column, section_band child). */
function childEditor(child, path, ctx, onTypeChange) {
    const select = h('select', { class: inputClass, 'aria-label': ctx.t('Block type'), onchange: (e) => onTypeChange(e.target.value) },
        h('option', { value: '' }, ctx.t('Choose a block…')),
        ctx.childTypes.map((type) => h('option', { value: type, selected: child.type === type }, ctx.typeLabel(type))));
    const type = ctx.types[child.type];
    child.settings = child.settings && typeof child.settings === 'object' && !Array.isArray(child.settings) ? child.settings : {};
    const hideId = nextId();
    return h('div', { class: 'flex flex-col gap-3' },
        select,
        h('div', { class: 'flex items-center justify-between gap-3' }, h('label', { for: hideId, class: 'text-sm' }, ctx.t('Visible on the page')), switchControl(hideId, !child.hidden, (on) => { child.hidden = !on; ctx.changed(); })),
        ctx.errorFor(`${path}.type`) ? h('p', { class: 'text-xs text-fg-danger' }, ctx.errorFor(`${path}.type`)) : null,
        type ? h('div', { class: 'flex flex-col gap-4 rounded-lg bg-page p-3' },
            h('div', { class: 'flex items-center gap-3' }, ctx.thumbnail(child.type, 'h-12 w-20'), h('p', { class: 'text-xs text-fg-secondary' }, type.description)),
            renderFields(type.fields.filter((f) => f.type !== 'datetime_range'), child.settings, `${path}.settings`, ctx)) : null);
}

function renderBlock(field, values, path, ctx) {
    const child = (values[field.key] = values[field.key] && typeof values[field.key] === 'object' && !Array.isArray(values[field.key]) ? values[field.key] : { type: '', settings: {} });
    const wrap = h('div', { class: 'flex flex-col gap-1.5', dataset: { fieldPath: path } });
    const paint = () => wrap.replaceChildren(h('span', { class: 'text-sm font-medium' }, field.label), childEditor(child, path, ctx, (type) => {
        child.type = type;
        child.settings = defaults(ctx.types[type]?.fields ?? []);
        ctx.changed();
        paint();
    }));
    paint();
    return wrap;
}

function renderBlocks(field, values, path, ctx) {
    const children = (values[field.key] = Array.isArray(values[field.key]) ? values[field.key] : []);
    const wrap = h('div', { class: 'flex flex-col gap-2', dataset: { fieldPath: path } });
    const paint = () => {
        wrap.replaceChildren(...[
            h('span', { class: 'flex items-center gap-1 text-sm font-medium' }, field.label, field.required ? h('span', { class: 'text-fg-danger' }, '*') : null),
            ctx.errorFor(path) ? h('p', { class: 'text-xs text-fg-danger' }, ctx.errorFor(path)) : null,
            ...children.map((child, index) => {
                const open = ctx.isOpen(child);
                return h('div', { class: `rounded-lg border ${ctx.countErrors(`${path}.${index}`) ? 'border-line-danger' : 'border-line'} bg-surface` },
                    h('div', { class: 'flex h-11 items-center gap-1 px-2' },
                        h('button', { type: 'button', class: 'flex min-w-0 flex-1 items-center gap-2 rounded px-1 py-1 text-start text-sm hover:bg-page', 'aria-expanded': String(open), onclick: () => { ctx.setOpen(child, !open); paint(); } },
                            h('span', { class: 'truncate' }, `${index + 1}. ${child.type ? ctx.typeLabel(child.type) : ctx.t('Choose a block…')}`), child.hidden ? icon('eye-off', 14, 'text-fg-muted') : null),
                        h('button', { type: 'button', class: buttonClass.icon, title: ctx.t('Move up'), disabled: index === 0, onclick: () => { children.splice(index - 1, 0, children.splice(index, 1)[0]); ctx.changed(); paint(); } }, icon('up', 14)),
                        h('button', { type: 'button', class: buttonClass.icon, title: ctx.t('Move down'), disabled: index === children.length - 1, onclick: () => { children.splice(index + 1, 0, children.splice(index, 1)[0]); ctx.changed(); paint(); } }, icon('down', 14)),
                        h('button', { type: 'button', class: `${buttonClass.icon} hover:text-fg-danger`, title: ctx.t('Remove'), onclick: () => { if (window.confirm(ctx.t('Remove this block?'))) { children.splice(index, 1); ctx.changed(); paint(); } } }, icon('trash', 14))),
                    open ? h('div', { class: 'border-t border-line p-3' }, childEditor(child, `${path}.${index}`, ctx, (type) => { child.type = type; child.settings = defaults(ctx.types[type]?.fields ?? []); ctx.changed(); paint(); })) : null);
            }),
            h('button', { type: 'button', class: 'flex h-10 items-center justify-center gap-2 rounded-lg border border-dashed border-line-strong text-sm font-medium text-fg-secondary hover:border-line-focus hover:text-fg disabled:opacity-40', disabled: Boolean(field.max && children.length >= field.max), onclick: () => { const fresh = { type: '', settings: {} }; children.push(fresh); ctx.setOpen(fresh, true); ctx.changed(); paint(); } }, icon('plus', 15), ctx.t('Add a block'))].filter(Boolean));
    };
    paint();
    return wrap;
}

const RENDERERS = {
    text: renderText,
    textarea: renderTextarea,
    rich_text: renderRichText,
    number: renderNumber,
    select: renderSelect,
    toggle: renderToggle,
    checkbox_group: renderCheckboxGroup,
    color: renderColor,
    datetime: renderDatetime,
    datetime_range: renderDatetimeRange,
    image: renderImage,
    link: renderLink,
    tags: renderTags,
    group: renderGroup,
    repeater: renderRepeater,
    block: renderBlock,
    blocks: renderBlocks,
};

export { placeholderThumb };
