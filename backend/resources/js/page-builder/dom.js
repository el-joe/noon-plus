/**
 * Tiny DOM helpers for the page builder (no framework, no packages).
 */

/**
 * Create an element: h('button', {class: 'x', onclick: fn, dataset: {a: 1}}, 'Text', child).
 */
export function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs ?? {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'dataset') Object.assign(el.dataset, value);
        else if (key === 'style' && typeof value === 'object') Object.assign(el.style, value);
        else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2), value);
        else if (key === 'value') el.value = value;
        else if (key === 'checked' || key === 'disabled' || key === 'selected' || key === 'hidden' || key === 'open') el[key] = Boolean(value);
        else if (key === 'html') el.innerHTML = value;
        else el.setAttribute(key, value === true ? '' : String(value));
    }
    append(el, children);
    return el;
}

function append(el, children) {
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
}

const ICONS = {
    grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
    eye: '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    up: '<path d="m18 15-6-6-6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    plus: '<path d="M5 12h14M12 5v14"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    image: '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
    layers: '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    settings: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    monitor: '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>',
    tablet: '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M12 18h.01"/>',
    phone: '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    external: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    chevron: '<path d="m9 18 6-6-6-6"/>',
    bold: '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>',
    italic: '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
    list: '<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    more: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    lock: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'alert-circle': '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
};

/** An outline icon (Lucide paths, ISC licence — see components/icon.blade.php). */
export function icon(name, size = 16, extra = '') {
    const span = document.createElement('span');
    span.className = `inline-flex shrink-0 ${extra}`;
    span.setAttribute('aria-hidden', 'true');
    span.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] ?? ''}</svg>`;
    return span;
}

/** Placeholder thumbnail when a block has no exported reference image yet. */
export function placeholderThumb(label, extra = '') {
    return h('div', { class: `flex size-full flex-col items-center justify-center gap-1 bg-subtle text-fg-muted ${extra}` }, icon('layers', 22), h('span', { class: 'px-2 text-center text-[11px] leading-tight' }, label));
}

export function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function requestJson(url, { method = 'GET', body = null, form = null } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() };
    if (body !== null) headers['Content-Type'] = 'application/json';
    let response;
    try {
        response = await fetch(url, { method, headers, body: form ?? (body !== null ? JSON.stringify(body) : null), credentials: 'same-origin' });
    } catch {
        return { ok: false, status: 0, data: null };
    }
    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }
    return { ok: response.ok, status: response.status, data };
}

export function debounce(fn, wait = 250) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

export function uid() {
    return window.crypto?.randomUUID ? window.crypto.randomUUID() : `b-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function clone(value) {
    return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

export const inputClass = 'h-10 w-full rounded-lg border border-line bg-surface px-3 text-sm text-fg placeholder:text-fg-muted focus:border-line-focus focus:outline-none focus:ring-2 focus:ring-brand/30';
export const buttonClass = {
    primary: 'inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-fg-on-brand hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus',
    outline: 'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line-strong bg-surface px-3 text-sm font-semibold text-fg hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus',
    ghost: 'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg px-2 text-sm text-fg hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus',
    icon: 'inline-grid size-8 shrink-0 place-items-center rounded-lg text-fg-secondary hover:bg-subtle hover:text-fg disabled:cursor-not-allowed disabled:opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-line-focus',
};
