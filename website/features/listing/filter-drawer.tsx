"use client";

import { SlidersHorizontal, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";

/**
 * Mobile filter sheet (category page design): a "Filters (n)" button opens
 * the rail as a full-height sheet from the start edge (RTL aware); it
 * closes on Escape, the backdrop, the close button, or once a filter
 * changed the URL.
 */
export function FilterDrawer({ count, children }: { count: number; children: ReactNode }) {
  const t = useTranslations("categoryPage");
  const [open, setOpen] = useState(false);
  const params = useSearchParams();
  const query = params.toString();
  const [seen, setSeen] = useState(query);

  // A filter was applied: close the sheet (adjusting state during render, not in an effect).
  if (seen !== query) {
    setSeen(query);
    if (open) setOpen(false);
  }

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => event.key === "Escape" && setOpen(false);
    document.addEventListener("keydown", onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = overflow;
    };
  }, [open]);

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-expanded={open} className="inline-flex h-9 items-center gap-2 rounded-md border border-line-strong bg-surface px-3 text-body-sm font-semibold">
        <SlidersHorizontal className="size-4" />
        {t("filtersTitle")}
        {count > 0 ? <span className="rounded-full bg-ink px-1.5 text-caption text-white">{count}</span> : null}
      </button>
      {open ? (
        <div className="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" aria-label={t("filtersTitle")}>
          <button type="button" aria-label={t("close")} className="absolute inset-0 bg-ink/50" onClick={() => setOpen(false)} />
          <div className="relative flex h-full w-[86%] max-w-sm flex-col bg-surface shadow-xl">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
              <h2 className="text-h4 font-semibold">{t("filtersTitle")}</h2>
              <button type="button" onClick={() => setOpen(false)} aria-label={t("close")} className="rounded-sm p-1 hover:bg-subtle">
                <X className="size-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-4">{children}</div>
          </div>
        </div>
      ) : null}
    </>
  );
}
