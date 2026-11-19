/**
 * Account-manager referral links (FR-FIN-6): `/join?ref=AM12-abcd1234` or
 * `/join/{type}?ref=…`. The code is remembered in this browser for 30 days
 * (`nawy_join_ref`) so it survives the chooser → wizard hop, and is sent
 * once with the account step (`POST partner-applications` `ref`). The API
 * checks it; an unknown code is ignored there.
 */
const KEY = "nawy_join_ref";
const TTL_MS = 30 * 24 * 60 * 60 * 1000;
const CODE = /^AM\d{1,12}-[A-Za-z0-9]{8}$/;

export const isReferralCode = (value: string | null | undefined): value is string => !!value && CODE.test(value.trim());

/** Store the `ref` of a URL query string when it looks like a referral code. */
export function captureReferral(search: string, now = Date.now()): string | null {
  const ref = new URLSearchParams(search).get("ref");
  if (!isReferralCode(ref)) return null;
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ code: ref.trim(), at: now }));
  } catch {
    // Storage blocked: the code still applies to this page's wizard.
  }
  return ref.trim();
}

/** The remembered referral code, if still fresh. */
export function storedReferral(now = Date.now()): string | null {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { code?: unknown; at?: unknown };
    if (!isReferralCode(typeof parsed.code === "string" ? parsed.code : null) || typeof parsed.at !== "number" || now - parsed.at > TTL_MS) {
      window.localStorage.removeItem(KEY);
      return null;
    }
    return parsed.code as string;
  } catch {
    return null;
  }
}

/** Forget it once an application was opened with it. */
export function clearReferral() {
  try {
    window.localStorage.removeItem(KEY);
  } catch {
    // ignore
  }
}
