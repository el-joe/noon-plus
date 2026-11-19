import { APPLICATION_TYPES } from "@/lib/api/portal";
import { TOKEN_COOKIE } from "@/lib/session";

/**
 * QA-012: real HTTP 404s for dynamic pages whose item does not exist.
 *
 * With Cache Components the prerendered shell (status 200) is sent before a
 * page's request-time lookup runs, so `notFound()` inside the page can only
 * render the 404 UI (with `noindex`), not change the status. The Next docs
 * ("Streaming → The HTTP contract") recommend checking in Proxy instead:
 * proxy.ts asks the API whether the item exists (a fast detail read) and,
 * when it answers 404, rewrites to `/{market}/missing`, a static route that
 * calls `notFound()` before anything streams.
 *
 * The check fails open: a timeout or API error renders the page as usual
 * (the page keeps its own `notFound()` for the UI). A lookup that also
 * checks the item's kind (a property page for a car ad) fails closed and is
 * never answered from the shared positive cache (RT-004).
 *
 * RT-004: under the static roots, a path that matches no page of
 * app/[market] (`help/nope`, `products/a/b`, `advertise/x`) is missing
 * without asking the API. Keep ROUTES in step with the page folders.
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");

/** Top-level folders of app/[market] (anything else is the category / CMS catch-all). */
const STATIC_ROOTS = new Set([
  "account", "adsupport", "advertise", "auth", "blog", "brands", "brokers", "cart", "checkout", "classified", "compare", "creators", "deals",
  "flash-sale", "gift-cards", "help", "join", "legal", "live", "marketers", "login", "membership", "missing", "newsletter", "pages", "products",
  "property", "r", "reset-password", "search", "sell", "seller", "travel", "unsubscribe",
]);

/**
 * Every page under the static roots (app/[market]/…/page.tsx), `:` for a
 * dynamic segment. A static-root path matching none of them is a 404.
 */
const ROUTES: string[][] = [
  "account", "account/addresses", "account/bookings", "account/cards", "account/contracts", "account/credits", "account/disputes", "account/disputes/new",
  "account/disputes/:", "account/favourites", "account/followed-stores", "account/gift-cards", "account/inquiries", "account/membership",
  "account/my-ads", "account/notifications", "account/notifications/settings", "account/orders", "account/orders/:", "account/profile", "account/returns", "account/returns/new",
  "account/returns/:", "account/reviews", "account/saved-searches", "account/security", "account/special-requests", "account/support",
  "account/support/new", "account/support/:", "account/vouchers", "account/warranty", "account/warranty/new", "account/warranty/:",
  "account/wishlist", "adsupport", "adsupport/articles/:", "adsupport/collections/:", "adsupport/search", "advertise", "auth/add-email", "auth/social/complete",
  "blog", "blog/category/:", "blog/:", "brands/:", "brokers/:", "cart", "checkout", "checkout/payment", "checkout/success", "classified",
  "classified/compare", "classified/find/:", "classified/post", "classified/search", "classified/shop/:", "compare", "creators", "creators/:",
  "deals", "flash-sale/:", "gift-cards", "help", "help/articles/:", "help/collections/:", "help/search", "join", "join/resume", "join/status",
  "join/:", "legal/seller-agreement", "live", "live/:", "login", "marketers", "marketers/:", "membership", "missing", "newsletter/confirm",
  "pages/:", "products/:", "property", "property/:", "r/:", "reset-password", "search", "sell", "sell/travel-agency", "seller/:", "travel",
  "travel/bookings", "travel/bookings/:", "travel/bookings/:/sent", "travel/search", "travel/:", "travel/:/book", "unsubscribe",
].map((route) => route.split("/"));

function matchesRoute(rest: string[]): boolean {
  return ROUTES.some((route) => route.length === rest.length && route.every((part, i) => part === ":" || part === rest[i]));
}

const SEGMENT = /^[a-z0-9-]+$/;
const SAFE = /^[A-Za-z0-9._~-]{1,200}$/;

/** `fresh`: never answered from the positive cache — the item's visibility changes at any time (BT-SOQ-04). */
type Lookup = { path: string; query?: Record<string, string>; auth?: boolean; booking?: string; fresh?: boolean; check?: (data: unknown) => boolean };
type Decision = Lookup[] | "missing" | null;

/**
 * What to ask the API for `/{market}/{...rest}`: lookups that must ALL answer
 * 404 for the page to be missing (the catch-all is missing only when neither
 * the CMS page nor the category exists), "missing" for malformed paths, or
 * null for routes without a lookup.
 */
function lookupsFor(rest: string[], search: URLSearchParams, hasToken: boolean, bookingToken: (n: string) => string | undefined): Decision {
  const decoded = rest.map((p) => {
    try {
      return decodeURIComponent(p);
    } catch {
      return "\u0000";
    }
  });
  const [root, a, b, c] = decoded;
  if (!root) return null;
  const n = rest.length;
  const enc = encodeURIComponent;
  const detail = (path: string, value: string | undefined, extra: Partial<Lookup> = {}): Decision =>
    value && SAFE.test(value) ? [{ path: `${path}/${enc(value)}`, ...extra }] : "missing";

  if (!STATIC_ROOTS.has(root)) {
    // Category PLP / page-builder page (app/[market]/[...slug]).
    if (n > 4 || rest.some((p) => !SEGMENT.test(p))) return "missing";
    const last = rest[n - 1];
    return [{ path: `pages/${last}` }, { path: `categories/${last}` }];
  }

  if (!matchesRoute(decoded)) return "missing";

  switch (root) {
    case "missing":
      return "missing";
    case "products":
      return n === 2 ? detail("products", a) : null;
    case "brands":
      return n === 2 ? detail("brands", a) : null;
    case "brokers":
      return n === 2 ? detail("brokers", a) : null;
    case "seller":
      // QC-008 product store; `/seller` alone has no page.
      return n === 2 ? detail("sellers", a) : "missing";
    case "creators":
      return n === 2 ? detail("creators", a) : null;
    case "live":
      return n === 2 ? detail("live-streams", a) : null;
    case "flash-sale":
      return n === 2 ? (a && /^[A-Za-z0-9-]{1,160}$/.test(a) ? detail("flash-sales", a) : "missing") : null;
    case "pages":
      return n === 2 ? detail("static-pages", a, { query: { audience: "storefront" } }) : null;
    case "property":
      // A classified ad that is not a property (e.g. a car) is not a property page.
      return n === 2 ? detail("classifieds/ads", a, { check: (data) => !!(data as { property?: unknown } | null)?.property }) : null;
    case "classified":
      // BT-SOQ-04: a seller can pause / archive / sell an ad at any moment, so its page
      // is checked on every view (a cached "found" kept an archived ad at HTTP 200).
      if (n === 3 && a === "find") return detail("classifieds/ads", b, { fresh: true });
      if (n === 3 && a === "shop") return detail("classifieds/shops", b);
      return null;
    case "blog":
      if (n === 2 && a !== "category") return detail("blog", a);
      if (n === 3 && a === "category") return b && SAFE.test(b) ? [{ path: "blog/categories", check: (data) => hasSlug(data, b) }] : "missing";
      return null;
    case "help":
    case "adsupport": {
      const hub = root === "help" ? "help_center" : "knowledge_hub";
      if (n === 3 && a === "articles") return detail(`help/${hub}/articles`, b);
      if (n === 3 && a === "collections") return b && SAFE.test(b) ? [{ path: `help/${hub}/collections`, check: (data) => hasSlug(data, b) }] : "missing";
      return null;
    }
    case "travel":
      if (a === "bookings" && b && (n === 3 || (n === 4 && c === "sent"))) {
        // Guest bookings open from an emailed link (?token=) or a device cookie; without either we cannot tell.
        if (search.has("token")) return null;
        const stored = bookingToken(b);
        if (!hasToken && !stored) return null;
        return detail("travel/bookings", b, { auth: hasToken, booking: stored });
      }
      if (a && a !== "search" && a !== "bookings" && (n === 2 || (n === 3 && b === "book"))) return detail("travel/packages", a);
      return null;
    case "r":
      return n === 2 ? (a && /^[A-Za-z0-9]{6,16}$/.test(a) ? detail("referrals", a) : "missing") : null;
    case "marketers":
      // QC-042: /marketers and one page per marketer role.
      return n > 2 || (n === 2 && !["influencer", "affiliate", "broker"].includes(a ?? "")) ? "missing" : null;
    case "legal":
      return n === 2 && a === "seller-agreement" ? null : "missing";
    case "join":
      return n === 2 && a !== "resume" && a !== "status" && !(APPLICATION_TYPES as readonly string[]).includes(a ?? "") ? "missing" : null;
    case "account": {
      // The customer's own records: only checkable with a session (guests see the sign-in prompt).
      if (!hasToken || n !== 3 || b === "new") return null;
      const paths: Record<string, string> = { orders: "orders", returns: "returns", warranty: "warranty-claims", disputes: "disputes", support: "support/tickets" };
      return a && paths[a] ? detail(paths[a], b, { auth: true }) : null;
    }
    default:
      return null;
  }
}

function hasSlug(data: unknown, slug: string): boolean {
  if (!Array.isArray(data)) return true;
  const walk = (nodes: unknown[]): boolean =>
    nodes.some((node) => {
      const item = node as { slug?: unknown; children?: unknown };
      return item?.slug === slug || (Array.isArray(item?.children) && walk(item.children));
    });
  return walk(data);
}

// Positive results for public lookups, briefly (keeps repeat views to one API call per minute).
const found = new Map<string, number>();
const FOUND_TTL_MS = 60_000;

async function exists(market: string, lookup: Lookup, token: string | undefined): Promise<boolean> {
  const qs = lookup.query ? `?${new URLSearchParams(lookup.query)}` : "";
  const url = `${API_ROOT}/${market}/${lookup.path}${qs}`;
  const cacheable = !lookup.auth && !lookup.booking && !lookup.check && !lookup.fresh;
  if (cacheable && (found.get(url) ?? 0) > Date.now()) return true;

  const headers: Record<string, string> = { Accept: "application/json" };
  if (lookup.auth && token) headers.Authorization = `Bearer ${token}`;
  if (lookup.booking) headers["X-Booking-Token"] = lookup.booking;

  try {
    const response = await fetch(url, { headers, cache: "no-store", signal: AbortSignal.timeout(lookup.check ? 8000 : 4000) });
    if (response.status === 404) return false;
    if (!response.ok) return !lookup.check;
    if (lookup.check) {
      const json = (await response.json().catch(() => null)) as { data?: unknown } | null;
      return !!json && lookup.check(json.data);
    }
    if (cacheable) {
      if (found.size > 1000) found.clear();
      found.set(url, Date.now() + FOUND_TTL_MS);
    }
    return true;
  } catch {
    // Fail open (the page renders and shows its own not-found UI), except when the kind must be checked.
    return !lookup.check;
  }
}

type CookieReader = { get(name: string): { value: string } | undefined };

/** True when `/{market}/{...rest}` names something the API says does not exist. */
export async function isMissing(market: string, rest: string[], search: URLSearchParams, cookies: CookieReader): Promise<boolean> {
  const token = cookies.get(TOKEN_COOKIE)?.value;
  const decision = lookupsFor(rest, search, !!token, (number) => cookies.get(`nawy_booking_${number.replace(/[^A-Za-z0-9-]/g, "")}`)?.value);
  if (decision === null) return false;
  if (decision === "missing") return true;
  const results = await Promise.all(decision.map((lookup) => exists(market, lookup, token)));
  return results.every((ok) => !ok);
}
