"use client";

import { X } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/misc";
import { useHref } from "@/lib/market-context";
import { takeMergeWarnings, type MergeWarning } from "@/lib/merge-warnings";

/**
 * QA-008: after sign-in the guest cart is merged into the customer's cart;
 * lines over a flash-sale limit or over stock are clipped or skipped and the
 * API lists them in `meta.merge_warnings`. Shown once, on the page the
 * customer lands on.
 */
export function MergeWarningsBanner() {
  const t = useTranslations("cart");
  const href = useHref();
  const [warnings, setWarnings] = useState<MergeWarning[]>([]);

  useEffect(() => {
    const taken = takeMergeWarnings();
    if (taken.length > 0) setWarnings(taken);
  }, []);

  if (warnings.length === 0) return null;

  return (
    <div className="mx-auto w-full max-w-(--container-page) px-4 pt-4 md:px-8">
      <Alert tone="warning" className="flex items-start gap-3">
        <div className="flex-1">
          <p className="font-semibold">{t("mergeWarningsTitle")}</p>
          <ul className="mt-1 list-disc ps-5">
            {warnings.map((w, i) => (
              <li key={`${w.listing_id ?? i}-${w.code ?? ""}`}>
                {w.message ?? t("mergeWarningFallback")}
                {typeof w.requested === "number" && typeof w.merged === "number" ? ` ${t("mergeWarningCounts", { requested: w.requested, merged: w.merged })}` : ""}
              </li>
            ))}
          </ul>
          <Link href={href("/cart")} className="mt-1 inline-block text-link underline">
            {t("mergeWarningsReview")}
          </Link>
        </div>
        <button type="button" onClick={() => setWarnings([])} aria-label={t("mergeWarningsDismiss")} className="shrink-0 rounded p-1 hover:bg-black/5">
          <X className="size-4" />
        </button>
      </Alert>
    </div>
  );
}
