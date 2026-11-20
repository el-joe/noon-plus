"use client";

import type { QueryClient } from "@tanstack/react-query";
import * as api from "@/lib/api/endpoints";
import type { Me } from "@/lib/api/schemas";
import { queryKeys } from "@/lib/hooks";
import { stashMergeWarnings } from "@/lib/merge-warnings";

/** Name of this browser in the customer's device list. */
export function deviceName(): string {
  const ua = navigator.userAgent;
  const browser = /Edg\//.test(ua) ? "Edge" : /Chrome\//.test(ua) ? "Chrome" : /Firefox\//.test(ua) ? "Firefox" : /Safari\//.test(ua) ? "Safari" : "Browser";
  const os = /Windows/.test(ua) ? "Windows" : /Mac OS/.test(ua) ? "macOS" : /Android/.test(ua) ? "Android" : /iPhone|iPad/.test(ua) ? "iOS" : /Linux/.test(ua) ? "Linux" : "";
  return `Web · ${browser}${os ? ` on ${os}` : ""}`.slice(0, 100);
}

/**
 * Only same-market, root-relative return paths (no open redirect). A path
 * without the market prefix (the API's `return_to`) is put under this market.
 * Backslashes, whitespace and control characters are refused outright:
 * browsers strip or normalise them, which can turn `/\t/evil.com` or
 * `/\\evil.com` into a protocol-relative `//evil.com`.
 */
export function safeNext(next: string | null | undefined, market: string): string {
  const fallback = `/${market}/account`;
  if (!next || next.length > 2000 || !next.startsWith("/") || next.startsWith("//") || /[\\\s\u0000-\u001f\u007f]/.test(next)) return fallback;
  // The resolved URL must stay on this origin.
  try {
    if (typeof window !== "undefined" && new URL(next, window.location.origin).origin !== window.location.origin) return fallback;
  } catch {
    return fallback;
  }
  if (next === `/${market}` || next.startsWith(`/${market}/`) || next.startsWith(`/${market}?`)) return next;
  // Another market's path: keep this market (a different market has its own session).
  if (/^\/[a-z]{3}-[a-z]{2}(\/|\?|#|$)/.test(next)) return fallback;
  return `/${market}${next}`;
}

/**
 * After any sign-in (the BFF already holds the token in its httpOnly cookie):
 * cache the user, merge the guest cart (keeping its warnings for the cart
 * page), refresh every query and go to `next`.
 */
export async function finishSignIn(client: QueryClient, market: string, user: Me | undefined, next: string | null | undefined): Promise<void> {
  if (user) client.setQueryData(queryKeys.me, user);
  try {
    const { meta } = await api.mergeCart(market);
    stashMergeWarnings(meta);
  } catch {
    // No guest cart or nothing to merge.
  }
  await client.invalidateQueries();
  window.location.href = safeNext(next, market);
}
