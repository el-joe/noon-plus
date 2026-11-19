/**
 * Cookie names shared by the BFF proxy and the browser.
 *
 * - `nawy_token`: Sanctum bearer token (httpOnly; the browser never reads it).
 * - `nawy_cart_{country}`: guest cart token per country DB (httpOnly).
 * - `nawy_visitor`: anonymous visitor token for sponsored ranking (httpOnly).
 * - Hints (readable, no secrets) tell the UI whether asking the API for the
 *   session or the cart is worth a request.
 */
export const TOKEN_COOKIE = "nawy_token";
export const AUTH_HINT_COOKIE = "nawy_signed_in";
export const VISITOR_COOKIE = "nawy_visitor";
export const cartCookie = (countryCode: string) => `nawy_cart_${countryCode}`;
export const cartHintCookie = (countryCode: string) => `nawy_cart_hint_${countryCode}`;

export function readBrowserCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.split("; ").find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

const LOCAL_DEV_HOST = /^(localhost|127(?:\.\d+){3}|\[::1\]|.+\.(?:loc|test|localhost))$/i;

/**
 * `Secure` flag for the cookies the BFF routes set: on in production builds,
 * except for a plain-http request on a local development host (the Apache
 * `*.noon-plus.loc` vhosts, `*.test`, localhost) — browsers drop `Secure`
 * cookies on `http://` hosts other than localhost, which would break sign-in
 * there. Real deployments are served over https and keep the flag.
 */
export function secureCookies(request: { headers: Headers }): boolean {
  if (process.env.NODE_ENV !== "production") return false;
  const proto = (request.headers.get("x-forwarded-proto") ?? "").split(",")[0].trim().toLowerCase();
  const host = (request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "").split(",")[0].trim().replace(/:\d+$/, "");
  return !(proto === "http" && LOCAL_DEV_HOST.test(host));
}

/** Host header value without a default port, lower-case. */
function bareHost(value: string | null | undefined): string | null {
  const host = (value ?? "").split(",")[0].trim().toLowerCase().replace(/:(80|443)$/, "");
  return host || null;
}

/**
 * Cross-site write check for the BFF routes (on top of SameSite=Lax): a
 * request whose `Origin` names another host than the one the browser
 * addressed. Behind a reverse proxy (Apache `ProxyPreserveHost`, a load
 * balancer) `nextUrl.origin` is the server's own bind address
 * (`localhost:3000`), so the public host comes from `Host` /
 * `X-Forwarded-Host`; the bind address stays accepted for direct access.
 */
export function isCrossSite(request: { headers: Headers; nextUrl: URL }): boolean {
  const origin = request.headers.get("origin");
  if (!origin) return false;
  let originHost: string | null;
  try {
    originHost = bareHost(new URL(origin).host);
  } catch {
    return true;
  }
  const allowed = [request.nextUrl.host, request.headers.get("x-forwarded-host"), request.headers.get("host")].map(bareHost);
  return originHost === null || !allowed.includes(originHost);
}
