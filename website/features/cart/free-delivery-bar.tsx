"use client";

import clsx from "clsx";
import { Truck } from "lucide-react";
import { useTranslations } from "next-intl";
import type { FreeDelivery } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";

/** G-A5: "Spend X more for free delivery" progress bar (cart `free_delivery`). */
export function FreeDeliveryBar({ freeDelivery, compact = false }: { freeDelivery: FreeDelivery | null | undefined; compact?: boolean }) {
  const t = useTranslations("commerce.freeDelivery");
  if (!freeDelivery) return null;
  const percent = Math.max(0, Math.min(100, Math.round(freeDelivery.progress_percent)));
  return (
    <div className={clsx("flex flex-col gap-2 rounded-lg border border-line bg-surface", compact ? "p-3" : "p-4")}>
      <p className={clsx("flex items-center gap-2 text-body-sm", freeDelivery.qualified ? "font-medium text-success-text" : "text-ink")}>
        <Truck className="size-4 shrink-0" />
        {freeDelivery.qualified ? t("qualified") : t("remaining", { amount: formatMoney(freeDelivery.remaining) })}
      </p>
      <div
        className="h-2 w-full overflow-hidden rounded-full bg-subtle"
        role="progressbar"
        aria-label={t("label")}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
      >
        <div className={clsx("h-full rounded-full transition-[width]", freeDelivery.qualified ? "bg-success" : "bg-brand")} style={{ width: `${percent}%` }} />
      </div>
      {!freeDelivery.qualified && !compact ? <p className="text-caption text-secondary">{t("threshold", { amount: formatMoney(freeDelivery.threshold) })}</p> : null}
    </div>
  );
}
