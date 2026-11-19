import { COUNTRIES } from "@/lib/markets";

/**
 * WP11 extras: old-platform URLs answer 301 on the website itself, from the
 * migration's 301 map (`legacy_redirects`, built by `legacy:redirects`).
 *
 * proxy.ts asks only for paths that would otherwise 404 (after
 * lib/missing-routes.ts decided "missing") and for un-prefixed paths that look
 * like the old seller portal (`/uae/helpcenter/…`, `/blog/…`, `/join`, …), so
 * ordinary pages never wait for it. The API answers from its own cache; this
 * module also keeps each answer (found or not) in memory for a while. Any
 * error or timeout means "no redirect" (the page renders or 404s as before).
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

const TTL_MS = 10 * 60_000;
const MAX_ENTRIES = 2000;
const TIMEOUT_MS = 2500;

/** First segments of the old seller portal / site pages without a market prefix. */
const PORTAL_ROOTS = new Set(["helpcenter", "adsupport", "blog", "join", "vendors", "marketers", "companies", "faq"]);

const cache = new Map<string, { to: string | null; until: number }>();

/** True for an un-prefixed path the old platform used (worth a lookup before the market redirect). */
export function isLegacyPortalPath(pathname: string): boolean {
  const first = pathname.split("/")[1]?.toLowerCase() ?? "";
  if (!first) return false;
  return PORTAL_ROOTS.has(first) || COUNTRIES.some((c) => c.code === first);
}

/** The path the map stores: no query, no trailing slash, at most 255 characters. */
export function normaliseLegacyPath(pathname: string): string | null {
  const raw = pathname.split("?")[0];
  if (!raw.startsWith("/") || raw.startsWith("//")) return null;
  const path = `/${raw.replace(/^\/+|\/+$/g, "")}`;
  if (path === "/" || path.length > 512 || path.includes("//")) return null;
  return path;
}

/**
 * The new path for an old URL (same origin, e.g. `/uae-en/account/orders/NW-…`),
 * or null when the map has none.
 */
export async function legacyRedirect(pathname: string): Promise<string | null> {
  const path = normaliseLegacyPath(pathname);
  if (!path) return null;

  const now = Date.now();
  const hit = cache.get(path);
  if (hit && hit.until > now) return hit.to;

  let to: string | null = null;
  try {
    const response = await fetch(`${API_ROOT}/legacy-redirects?${new URLSearchParams({ path })}`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (response.ok) {
      const json = (await response.json().catch(() => null)) as { data?: { to?: unknown } } | null;
      const target = json?.data?.to;
      // Only same-site paths: never send a visitor to another host from the map.
      to = typeof target === "string" && target.startsWith("/") && !target.startsWith("//") ? target : null;
    } else if (response.status !== 404) {
      return null; // API trouble: no redirect, and nothing cached.
    }
  } catch {
    return null;
  }

  if (cache.size >= MAX_ENTRIES) cache.clear();
  cache.set(path, { to, until: now + TTL_MS });
  return to;
}
