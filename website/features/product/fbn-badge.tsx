"use client";

import { PackageCheck } from "lucide-react";
import { useTranslations } from "next-intl";
import type { Offer } from "@/lib/api/schemas";

/** True when the offer ships from a Nawy warehouse (FBN). */
export function isFulfilledByNawy(offer: Pick<Offer, "fulfilment"> & { fulfilled_by_nawy?: boolean }): boolean {
  return offer.fulfilled_by_nawy === true || offer.fulfilment === "fbn";
}

/** "Fulfilled by Nawy" chip on a PDP offer (buy box, seller card, other offers). */
export function FulfilledByNawyBadge({ offer, withHint = false }: { offer: Pick<Offer, "fulfilment"> & { fulfilled_by_nawy?: boolean }; withHint?: boolean }) {
  const t = useTranslations("discovery.pdp");
  if (!isFulfilledByNawy(offer)) return null;

  return (
    <span className="inline-flex items-center gap-1 rounded-xs bg-brand px-1.5 py-0.5 text-caption font-semibold text-ink" data-testid="fbn-badge" title={t("fbnBadgeHint")}>
      <PackageCheck className="size-3.5" aria-hidden />
      {t("fbnBadge")}
      {withHint ? <span className="sr-only"> — {t("fbnBadgeHint")}</span> : null}
    </span>
  );
}
