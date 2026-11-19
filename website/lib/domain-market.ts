import { parseMarket } from "@/lib/markets";

/**
 * QC-044 custom domains: what the request host serves, from the admin's
 * domain map (`GET /api/v1/domains/resolve?host=`). proxy.ts sends an
 * un-prefixed URL on a mapped storefront host (e.g. `nawy.om/`) to that
 * host's market (`/omn-ar`) instead of the cookie / default market, and a
 * host with `redirect_to` answers 301 to that host. Answers (found or not)
 * are kept in memory for 5 minutes; any error or timeout means "not mapped".
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

const TTL_MS = 5 * 60_000;
const MAX_ENTRIES = 500;
const TIMEOUT_MS = 1500;

export type HostMapping = {
  kind: "storefront" | "panel" | "portal" | "api";
  market: string | null;
  redirectTo: string | null;
  canonicalHost: string | null;
};

const cache = new Map<string, { value: HostMapping | null; until: number }>();

/** Lower-case host without port; null for local / IP hosts that are never mapped. */
export function normaliseHost(host: string | null | undefined): string | null {
  const value = (host ?? "").trim().toLowerCase().replace(/:\d+$/, "").replace(/\.$/, "");
  if (!value || !value.includes(".") || /^[\d.]+$/.test(value) || value.startsWith("[")) return null;
  return /^[a-z0-9.-]+$/.test(value) ? value : null;
}

export async function hostMapping(host: string | null | undefined): Promise<HostMapping | null> {
  const key = normaliseHost(host);
  if (!key) return null;

  const now = Date.now();
  const hit = cache.get(key);
  if (hit && hit.until > now) return hit.value;

  let value: HostMapping | null = null;
  try {
    const response = await fetch(`${API_ROOT}/domains/resolve?${new URLSearchParams({ host: key })}`, {
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (response.ok) {
      const json = (await response.json().catch(() => null)) as { data?: Record<string, unknown> } | null;
      const data = json?.data ?? {};
      const kind = data.kind;
      if (kind === "storefront" || kind === "panel" || kind === "portal" || kind === "api") {
        const market = typeof data.market === "string" ? parseMarket(data.market)?.slug ?? null : null;
        const redirectTo = typeof data.redirect_to === "string" ? normaliseHost(data.redirect_to) : null;
        const canonicalHost = typeof data.canonical_host === "string" ? normaliseHost(data.canonical_host) : null;
        value = { kind, market, redirectTo, canonicalHost };
      }
    } else if (response.status !== 404) {
      return null; // API trouble: not cached.
    }
  } catch {
    return null;
  }

  if (cache.size >= MAX_ENTRIES) cache.clear();
  cache.set(key, { value, until: now + TTL_MS });
  return value;
}
