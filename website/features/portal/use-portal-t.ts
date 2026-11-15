"use client";

import { useTranslations } from "next-intl";

/**
 * `portal.*` lookups with keys built at run time (API step names, statuses,
 * field names). Unknown keys fall back instead of throwing.
 */
export function usePortalLabel() {
  const t = useTranslations("portal");
  const loose = t as unknown as ((key: string, values?: Record<string, string | number>) => string) & { has: (key: string) => boolean };
  return (key: string, fallback?: string, values?: Record<string, string | number>) => (loose.has(key) ? loose(key, values) : (fallback ?? key.split(".").pop() ?? key));
}
