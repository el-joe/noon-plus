import type { QueryClient } from "@tanstack/react-query";
import { updateProfile } from "./api/account";
import type { Locale } from "./markets";
import { AUTH_HINT_COOKIE, readBrowserCookie } from "./session";

/**
 * QC-028: emails and in-app notifications use the customer's saved language,
 * so a signed-in customer's language switch is sent as `PATCH me {locale}`.
 * Failures are ignored (the switch itself always happens); waits at most
 * ~1.5 s so a full-page navigation is not held up.
 */
export async function syncLocale(market: string, locale: Locale, client?: QueryClient): Promise<void> {
  if (readBrowserCookie(AUTH_HINT_COOKIE) !== "1") return;
  const save = updateProfile(market, { locale })
    .then(() => client?.invalidateQueries({ queryKey: ["me"] }))
    .catch(() => undefined);
  await Promise.race([save, new Promise((resolve) => setTimeout(resolve, 1500))]);
}
