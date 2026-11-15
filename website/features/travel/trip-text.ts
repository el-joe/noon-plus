/** Tolerant readers for package JSON lists (itinerary, inclusions, cancellation policy). */
export function textItems(value: unknown): string[] {
  const list = Array.isArray(value) ? value : value && typeof value === "object" ? Object.values(value) : [];
  return list
    .map((item) => {
      if (typeof item === "string") return item;
      if (item && typeof item === "object") {
        const o = item as Record<string, unknown>;
        return String(o.title ?? o.text ?? o.name ?? o.label ?? "");
      }
      return "";
    })
    .filter((s) => s.trim() !== "");
}

export type ItineraryDay = { day: string; title: string; details: string | null };

export function itineraryDays(value: unknown): ItineraryDay[] {
  const list = Array.isArray(value) ? value : [];
  return list
    .map((item, index): ItineraryDay | null => {
      if (typeof item === "string") return item.trim() ? { day: String(index + 1), title: item, details: null } : null;
      if (item && typeof item === "object") {
        const o = item as Record<string, unknown>;
        const title = String(o.title ?? o.text ?? o.name ?? "");
        if (!title) return null;
        return { day: String(o.day ?? index + 1), title, details: typeof o.details === "string" ? o.details : typeof o.description === "string" ? o.description : null };
      }
      return null;
    })
    .filter((d): d is ItineraryDay => d !== null);
}

export type RefundTier = { days: number; percent: number };

export function refundTiers(value: unknown): RefundTier[] {
  const list = Array.isArray(value) ? value : [];
  return list
    .map((tier) => {
      if (!tier || typeof tier !== "object") return null;
      const o = tier as Record<string, unknown>;
      const days = Number(o.days_before);
      const percent = Number(o.refund_percent);
      return Number.isFinite(days) && Number.isFinite(percent) ? { days, percent } : null;
    })
    .filter((t): t is RefundTier => t !== null)
    .sort((a, b) => b.days - a.days);
}
