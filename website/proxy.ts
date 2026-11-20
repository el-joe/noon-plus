import { NextResponse, type NextRequest } from "next/server";
import { COUNTRIES, DEFAULT_MARKET, LOCALES, MARKET_COOKIE, parseMarket } from "@/lib/markets";
import { isMissing } from "@/lib/missing-routes";
import { isLegacyPortalPath, legacyRedirect } from "@/lib/legacy-redirects";
import { hostMapping } from "@/lib/domain-market";

/**
 * - URLs without a market prefix redirect to the visitor's last market
 *   (cookie) or the default market (MASTER_PLAN §4a: "URLs without a prefix
 *   redirect to the session country"). Visiting a market remembers it.
 * - QA-031: a first segment that is a market in the wrong case (`/UAE-EN`)
 *   redirects to the lower-case market; one that only looks like a market
 *   (a known country with an unknown language such as `/uae-fr`, or an
 *   unknown country with en/ar such as `/xyz-en`) is a 404 rather than being
 *   nested under another market (`/uae-en/xyz-en/cart`).
 * - QA-012: detail pages whose item does not exist answer a real 404
 *   (see lib/missing-routes.ts).
 * - WP11 extras: an old-platform URL answers 301 to its new page from the
 *   migration's map (lib/legacy-redirects.ts). Only paths that would 404 and
 *   un-prefixed old-portal paths are looked up; other requests never wait.
 * - The partner portal host (`business.*` or a `portal` domain) shows the
 *   partner pages only; other market pages redirect to `/{market}/join`.
 * - QC-044: a custom domain from the admin domain map (lib/domain-market.ts)
 *   — a host with `redirect_to` answers 301 to that host; on a storefront
 *   host (e.g. `nawy.om`) un-prefixed URLs open its market instead of the
 *   remembered / default one.
 */
export async function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const parts = pathname.split("/");
  const first = parts[1] ?? "";
  const market = parseMarket(first);
  const mapping = await hostMapping(request.headers.get("host"));

  if (mapping?.redirectTo) {
    const url = request.nextUrl.clone();
    url.host = mapping.redirectTo;
    url.port = "";
    return NextResponse.redirect(url, 301);
  }

  // The partner portal host (business.*, or a mapped `portal` domain) serves the partner pages only:
  // any other market page (storefront home, categories…) opens the portal landing `/{market}/join`.
  if (market && isPortalHost(request.headers.get("host"), mapping?.kind) && !PORTAL_SECTIONS.has(parts[2] ?? "")) {
    const url = request.nextUrl.clone();
    url.pathname = `/${market.slug}/join`;
    url.search = "";
    return NextResponse.redirect(url, 307);
  }

  if (market) {
    const prefetch = request.headers.has("next-router-prefetch");
    const missing = !prefetch && (request.method === "GET" || request.method === "HEAD") && (await isMissing(market.slug, parts.slice(2).filter(Boolean), request.nextUrl.searchParams, request.cookies));
    if (missing) {
      const target = await legacyRedirect(pathname);
      if (target) return permanentRedirect(request, target, search);
    }
    // Client navigations (RSC requests) get the not-found payload without the status, which the router would treat as a hard error.
    const status = request.headers.has("rsc") ? undefined : 404;
    const response = missing ? NextResponse.rewrite(new URL(`/${market.slug}/missing`, request.url), { status }) : NextResponse.next();
    if (request.cookies.get(MARKET_COOKIE)?.value !== market.slug) {
      response.cookies.set(MARKET_COOKIE, market.slug, { path: "/", maxAge: 60 * 60 * 24 * 365, sameSite: "lax" });
    }
    return response;
  }

  const hostMarket = mapping?.kind === "storefront" ? mapping.market : null;
  const remembered = hostMarket ?? parseMarket(request.cookies.get(MARKET_COOKIE)?.value)?.slug ?? DEFAULT_MARKET;

  const lower = parseMarket(first.toLowerCase());
  if (lower) {
    const url = request.nextUrl.clone();
    url.pathname = ["", lower.slug, ...parts.slice(2)].join("/");
    url.search = search;
    return NextResponse.redirect(url, 308);
  }

  if (looksLikeMarket(first)) {
    return NextResponse.rewrite(new URL(`/${remembered}/missing`, request.url), { status: 404 });
  }

  if ((request.method === "GET" || request.method === "HEAD") && !request.headers.has("next-router-prefetch") && isLegacyPortalPath(pathname)) {
    const target = await legacyRedirect(pathname);
    if (target) return permanentRedirect(request, target, search);
  }

  const url = request.nextUrl.clone();
  url.pathname = `/${remembered}${pathname === "/" ? "" : pathname}`;
  url.search = search;
  return NextResponse.redirect(url);
}

/** 301 to a same-site path from the legacy map, keeping the visitor's query unless the target has its own. */
function permanentRedirect(request: NextRequest, target: string, search: string) {
  const url = new URL(target, request.url);
  if (!url.search && search) url.search = search;
  return NextResponse.redirect(url, 301);
}

/** First path segments (after the market) the partner portal host serves. */
const PORTAL_SECTIONS = new Set(["join", "sell", "advertise", "adsupport", "help", "legal", "login", "auth", "reset-password", "pages", "missing"]);

/** The public partner portal host: `business.` (local / default naming) or a domain mapped as `portal`. */
function isPortalHost(host: string | null, kind: string | undefined): boolean {
  if (kind === "portal") return true;
  return /^business\./i.test((host ?? "").trim());
}

/** `{country}-{lang}` where one half is ours (`uae-fr`, `xyz-en`); other paths are store paths. */
function looksLikeMarket(segment: string): boolean {
  const match = /^([a-z]{3})-([a-z]{2})$/i.exec(segment);
  if (!match) return false;
  const country = match[1].toLowerCase();
  const locale = match[2].toLowerCase();
  return COUNTRIES.some((c) => c.code === country) || (LOCALES as readonly string[]).includes(locale);
}

export const config = {
  // Skip Next internals, the BFF API and files with an extension (public assets).
  matcher: ["/((?!api/|_next/|.*\\.[a-zA-Z0-9]+$).*)"],
};
