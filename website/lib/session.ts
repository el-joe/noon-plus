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
