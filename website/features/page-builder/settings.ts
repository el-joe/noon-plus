import type { Locale, Market } from "@/lib/markets";
import { parseMarket } from "@/lib/markets";
import type { Category } from "@/lib/api/schemas";

/**
 * Tolerant readers for block `settings` (free-form JSON saved by Admin →
 * Content → Page builder; keys per docs/design/page-builder-blocks.md).
 * Localised keys may be `title` or `title_en` / `title_ar`.
 */
export type Settings = Record<string, unknown>;

export type BlockContext = {
  market: Market;
  /** Root categories (for `source: category children` / sub-category blocks). */
  categories: Category[];
  /** The category whose page is rendering, when any. */
  category?: { slug: string; name: string; children: Category[]; path: string[] } | null;
  /** Search / listing state forwarded to the listing zone. */
  listing?: { query?: Record<string, string | undefined>; basePath: string } | null;
  depth?: number;
};

export function str(s: Settings, key: string, locale?: Locale): string | null {
  const candidates = locale ? [`${key}_${locale}`, key] : [key];
  for (const k of candidates) {
    const v = s[k];
    if (typeof v === "string" && v.trim() !== "") return v;
    if (typeof v === "number") return String(v);
    if (v && typeof v === "object" && locale && typeof (v as Record<string, unknown>)[locale] === "string") {
      return (v as Record<string, string>)[locale];
    }
  }
  return null;
}

export function num(s: Settings, key: string, fallback: number): number {
  const v = Number(s[key]);
  return Number.isFinite(v) && s[key] !== null && s[key] !== "" ? v : fallback;
}

export function bool(s: Settings, key: string, fallback = false): boolean {
  const v = s[key];
  return typeof v === "boolean" ? v : v === "1" || v === 1 || v === "true" ? true : v === "0" || v === 0 || v === "false" ? false : fallback;
}

export function list(s: Settings, key: string): Settings[] {
  const v = s[key];
  return Array.isArray(v) ? v.filter((x): x is Settings => !!x && typeof x === "object" && !Array.isArray(x)) : [];
}

export function strings(s: Settings, key: string): string[] {
  const v = s[key];
  if (Array.isArray(v)) return v.filter((x): x is string => typeof x === "string" && x.trim() !== "");
  if (typeof v === "string") return v.split(/[\n,]/).map((x) => x.trim()).filter(Boolean);
  return [];
}

export type Item = { image: string | null; imageMobile: string | null; link: string | null; label: string | null; caption: string | null; alt: string; sticker: string | null };

/** One slide / tile / banner item in any of the editor's spellings. */
export function item(s: Settings, locale: Locale): Item {
  const image = str(s, "image_d") ?? str(s, "image") ?? str(s, "image_url") ?? str(s, "src") ?? str(s, "logo");
  const label = str(s, "label", locale) ?? str(s, "title", locale) ?? str(s, "name", locale) ?? str(s, "text", locale);
  return {
    image: safeUrl(image),
    imageMobile: safeUrl(str(s, "image_m")),
    link: str(s, "link") ?? str(s, "url") ?? str(s, "href") ?? str(s, "link_url"),
    label,
    caption: str(s, "caption", locale) ?? str(s, "subtitle", locale) ?? str(s, "offer", locale),
    alt: str(s, "alt", locale) ?? label ?? "",
    sticker: str(s, "sticker", locale) ?? str(s, "badge", locale),
  };
}

export function items(s: Settings, key: string, locale: Locale): Item[] {
  return list(s, key).map((x) => item(x, locale));
}

/** Only http(s) and root-relative image URLs. */
export function safeUrl(url: string | null): string | null {
  if (!url) return null;
  return /^(https?:)?\/\//i.test(url) || url.startsWith("/") ? url : null;
}

/** Resolve an editor link: absolute http(s) stays, "/x" gets the market prefix. Anything else (javascript:, …) is dropped. */
export function resolveLink(link: string | null | undefined, market: Market): string | null {
  if (!link) return null;
  if (/^https?:\/\//i.test(link)) return link;
  if (!link.startsWith("/")) return null;
  const first = link.split("/")[1];
  return parseMarket(first) ? link : `/${market.slug}${link === "/" ? "" : link}`;
}

export function blockTitle(s: Settings, locale: Locale): string | null {
  return str(s, "title", locale) ?? str(s, "heading", locale);
}

export function background(s: Settings): string | undefined {
  const bg = s.background;
  const value = typeof bg === "string" ? bg : bg && typeof bg === "object" ? ((bg as Settings).color as string | undefined) : undefined;
  // Editor hex colours, or a design token (QC-077: no hex fallbacks in code).
  return value && (/^#[0-9a-f]{3,8}$/i.test(value) || /^var\(--color-[a-z0-9-]+\)$/.test(value)) ? value : undefined;
}
