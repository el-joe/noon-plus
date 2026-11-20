"use client";

import { CheckCircle2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useLayoutEffect, useRef } from "react";
import { Logo } from "@/components/layout/logo";
import { useHref } from "@/lib/market-context";

/**
 * Minimal checkout header (Figma Checkout 25:605, QC-058): logo and "Secure
 * checkout" only. Its `data-chrome="checkout"` marker hides the store header
 * and footer through a CSS `:has()` rule in globals.css, so the shared market
 * layout stays static (no pathname read).
 *
 * With `cacheComponents`, Next keeps the last routes mounted inside a hidden
 * React <Activity> after a client navigation, so this div stays in the DOM
 * (display: none) on the next page — e.g. /login reached from checkout — and
 * the `:has()` rule would keep hiding the store header there. Activity runs
 * effect cleanups when it hides a route, so the marker is switched off with
 * `data-inactive` while hidden and back on when the route is shown again.
 * Server HTML has no `data-inactive`, so a direct checkout load never
 * flashes the store header.
 */
export function CheckoutChrome() {
  const t = useTranslations("checkout");
  const href = useHref();
  const ref = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    const el = ref.current;
    el?.removeAttribute("data-inactive");
    return () => el?.setAttribute("data-inactive", "");
  }, []);
  return (
    <div ref={ref} data-chrome="checkout" className="border-b border-line bg-surface">
      <div className="mx-auto flex h-[72px] max-w-(--container-page) items-center justify-between gap-4 px-4 md:px-8">
        <Logo href={href("/")} />
        <span className="flex items-center gap-1.5 text-body font-medium text-success-text">
          <CheckCircle2 className="size-5" /> {t("secure")}
        </span>
      </div>
    </div>
  );
}
