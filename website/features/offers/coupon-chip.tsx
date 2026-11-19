"use client";

import clsx from "clsx";
import { Check, Copy, Tag } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import type { PublicCoupon } from "@/lib/api/discovery";
import * as api from "@/lib/api/endpoints";
import { formatDay, formatMoney } from "@/lib/format";
import { useCartMutation, useErrorMessage, useLoginRedirect, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * Public coupon chip (Figma 24:2 dashed chips; home "Offers for you"):
 * label, code, conditions, Copy and Apply to cart.
 */
export function CouponChip({ coupon, className }: { coupon: PublicCoupon; className?: string }) {
  const t = useTranslations("discovery.coupons");
  const market = useMarket();
  const { isSignedIn } = useSession();
  const toLogin = useLoginRedirect();
  const message = useErrorMessage();
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const apply = useCartMutation((m, code: string) => api.applyCoupon(m, code));

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(coupon.code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  const conditions = [
    coupon.min_order ? t("minOrder", { amount: formatMoney(coupon.min_order) }) : null,
    coupon.max_discount ? t("maxDiscount", { amount: formatMoney(coupon.max_discount) }) : null,
    coupon.seller?.name ? t("seller", { name: coupon.seller.name }) : null,
    coupon.first_order_only ? t("firstOrder") : null,
    coupon.ends_at ? t("endsOn", { date: formatDay(coupon.ends_at, market.locale) }) : null,
  ].filter(Boolean);

  return (
    <div className={clsx("flex min-w-0 flex-col gap-1.5 rounded-md border border-dashed border-success bg-success-subtle/40 px-3 py-2", className)}>
      <div className="flex items-start gap-2">
        <Tag className="mt-0.5 size-4 shrink-0 text-success-text" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-body-sm font-semibold">{coupon.label ?? coupon.code}</p>
          <p className="text-caption text-secondary">
            {t("code")}{" "}
            <span className="font-mono font-semibold text-ink" dir="ltr">
              {coupon.code}
            </span>
            {conditions.length > 0 ? ` · ${conditions.join(" · ")}` : ""}
          </p>
        </div>
      </div>
      <div className="flex items-center gap-3 ps-6 text-body-sm">
        <button type="button" onClick={copy} className="flex items-center gap-1 font-medium text-link hover:underline" aria-label={t("copyCode", { code: coupon.code })}>
          {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
          {copied ? t("copied") : t("copy")}
        </button>
        <button
          type="button"
          disabled={apply.isPending || apply.isSuccess}
          onClick={() => {
            setError(null);
            if (coupon.requires_sign_in && !isSignedIn) {
              toLogin();
              return;
            }
            apply.mutate(coupon.code, { onError: (e) => setError(message(e)) });
          }}
          className="font-medium text-link hover:underline disabled:text-success-text disabled:no-underline"
        >
          {apply.isSuccess ? t("applied") : apply.isPending ? t("applying") : t("apply")}
        </button>
      </div>
      {error ? <p className="ps-6 text-caption text-danger-text">{error}</p> : null}
    </div>
  );
}
