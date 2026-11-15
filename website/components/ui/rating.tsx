import clsx from "clsx";
import { Star } from "lucide-react";

/** Read-only star row (0–5, halves rounded down to the nearest half). */
export function Stars({ value, size = "sm", className }: { value: number | string | null | undefined; size?: "sm" | "md" | "lg"; className?: string }) {
  const n = Math.max(0, Math.min(5, Number(value ?? 0) || 0));
  const px = { sm: "size-3.5", md: "size-4", lg: "size-5" }[size];
  return (
    <span className={clsx("inline-flex items-center gap-0.5", className)} aria-label={`${n.toFixed(1)} / 5`} role="img">
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className="relative inline-block">
          <Star className={clsx(px, "text-line-strong")} fill="currentColor" strokeWidth={0} />
          {n >= i - 0.75 ? (
            <span className="absolute inset-0 overflow-hidden" style={{ width: n >= i - 0.25 ? "100%" : "50%" }}>
              <Star className={clsx(px, "text-brand")} fill="currentColor" strokeWidth={0} />
            </span>
          ) : null}
        </span>
      ))}
    </span>
  );
}

/** Compact "★ 4.5 (120)" badge used on cards and the PDP header. */
export function RatingBadge({ average, count, className }: { average: number | string | null | undefined; count: number; className?: string }) {
  if (!count || average == null) return null;
  return (
    <span className={clsx("inline-flex items-center gap-1 text-caption", className)}>
      <span className="inline-flex items-center gap-0.5 rounded-xs bg-success px-1 font-semibold text-white">
        {Number(average).toFixed(1)}
        <Star className="size-3" fill="currentColor" strokeWidth={0} />
      </span>
      <span className="text-muted">({count})</span>
    </span>
  );
}
