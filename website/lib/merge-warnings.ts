/**
 * Cart merge warnings (QA-008): `POST cart/merge` answers with
 * `meta.merge_warnings` when lines were clipped or skipped. Sign-in stashes
 * them here (the page then navigates away) and the cart UI shows them.
 */
export const MERGE_WARNINGS_KEY = "nawy_merge_warnings";

export function stashMergeWarnings(meta: Record<string, unknown>): void {
  const warnings = meta.merge_warnings;
  if (!Array.isArray(warnings) || warnings.length === 0) return;
  try {
    window.sessionStorage.setItem(MERGE_WARNINGS_KEY, JSON.stringify(warnings));
  } catch {
    // Storage disabled: the merged cart is still correct, only the notice is lost.
  }
}

export type MergeWarning = { listing_id?: number; code?: string; message?: string; requested?: number; merged?: number };

/** Read and clear the stashed warnings (shown once, after the sign-in redirect). */
export function takeMergeWarnings(): MergeWarning[] {
  try {
    const raw = window.sessionStorage.getItem(MERGE_WARNINGS_KEY);
    if (!raw) return [];
    window.sessionStorage.removeItem(MERGE_WARNINGS_KEY);
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed.filter((w) => w && typeof w === "object") as MergeWarning[]) : [];
  } catch {
    return [];
  }
}
