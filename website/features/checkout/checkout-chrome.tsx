"use client";

import { CheckCircle2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Logo } from "@/components/layout/logo";
import { useHref } from "@/lib/market-context";

/**
 * Minimal checkout header (Figma Checkout 25:605, QC-058): logo and "Secure
 * checkout" only. Its `data-chrome="checkout"` marker hides the store header
 * and footer through a CSS `:has()` rule in globals.css, so the shared market
 * layout stays static (no pathname read).
 */
export function CheckoutChrome() {
  const t = useTranslations("checkout");
  const href = useHref();
  return (
    <div data-chrome="checkout" className="border-b border-line bg-surface">
      <div className="mx-auto flex h-[72px] max-w-(--container-page) items-center justify-between gap-4 px-4 md:px-8">
        <Logo href={href("/")} />
        <span className="flex items-center gap-1.5 text-body font-medium text-success-text">
          <CheckCircle2 className="size-5" /> {t("secure")}
        </span>
      </div>
    </div>
  );
}
