"use client";

import { Check, Tag, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent, type ReactNode } from "react";
import type { Cart } from "@/lib/api/schemas";
import * as api from "@/lib/api/endpoints";
import { formatMoney, isZero } from "@/lib/format";
import { ApiError } from "@/lib/api/client";
import { useCartMutation, useErrorMessage, useLoginRedirect } from "@/lib/hooks";
import { Alert } from "@/components/ui/misc";
import { Button } from "../../components/ui/button";

export function Row({ label, value, tone }: { label: ReactNode; value: ReactNode; tone?: "success" }) {
  return (
    <div className="flex items-center justify-between gap-3 text-body">
      <span className="text-secondary">{label}</span>
      <span className={tone === "success" ? "font-medium text-success-text" : "font-medium"}>{value}</span>
    </div>
  );
}

/** Totals block shared by cart and checkout (CartPresenter totals). */
export function Totals({ cart }: { cart: Cart }) {
  const t = useTranslations();
  const tt = cart.totals;
  const items = cart.shipments.reduce((n, s) => n + s.items.reduce((m, i) => m + i.quantity, 0), 0);
  return (
    <div className="flex flex-col gap-2.5">
      <Row label={`${t("cart.subtotal")} (${t("common.items", { count: items })})`} value={formatMoney(tt.subtotal)} />
      <Row label={t("cart.shipping")} value={isZero(tt.shipping) ? t("common.free") : formatMoney(tt.shipping)} tone={isZero(tt.shipping) ? "success" : undefined} />
      {cart.coupons.map((c) => (
        <Row key={c.code} label={`${t("cart.discount")} ${c.code}`} value={`− ${formatMoney(c.discount)}`} tone="success" />
      ))}
      {cart.coupons.length === 0 && !isZero(tt.discount) ? <Row label={t("cart.discount")} value={`− ${formatMoney(tt.discount)}`} tone="success" /> : null}
      {!isZero(tt.warranty) ? <Row label={t("cart.warranty")} value={formatMoney(tt.warranty)} /> : null}
      {!isZero(tt.cod_fee) ? <Row label={t("cart.codFee")} value={formatMoney(tt.cod_fee)} /> : null}
      {tt.tax_rate && Number(tt.tax_rate) > 0 ? <Row label={t("cart.vatIncluded", { rate: Number(tt.tax_rate).toString() })} value={formatMoney(tt.tax_included)} /> : null}
      <div className="mt-2 flex items-center justify-between border-t border-line pt-3">
        <span className="text-h4 font-semibold">{t("cart.total")}</span>
        <span className="text-h2 font-bold">{formatMoney(tt.total)}</span>
      </div>
    </div>
  );
}

/** Coupon input + applied coupons (1 platform + 1 seller coupon rule is enforced by the API). */
export function CouponBox({ cart }: { cart: Cart }) {
  const t = useTranslations();
  const [code, setCode] = useState("");
  const [error, setError] = useState<{ message: string; code: string } | null>(null);
  const message = useErrorMessage();
  const toLogin = useLoginRedirect();
  const apply = useCartMutation((m, c: string) => api.applyCoupon(m, c));
  const remove = useCartMutation((m, c: string) => api.removeCoupon(m, c));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    apply.mutate(code.trim(), { onSuccess: () => setCode(""), onError: (e) => setError({ message: message(e), code: e instanceof ApiError ? e.code : "" }) });
  };

  return (
    <div className="flex flex-col gap-2">
      <form onSubmit={submit} className="flex gap-2">
        <label className="relative flex-1">
          <Tag className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <input
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            maxLength={32}
            placeholder={t("cart.couponPlaceholder")}
            aria-label={t("cart.couponPlaceholder")}
            className="h-10 w-full rounded-md border border-line-strong bg-surface ps-9 pe-2 text-body uppercase"
          />
        </label>
        <Button type="submit" variant="dark" disabled={!code.trim() || apply.isPending}>
          {t("common.apply")}
        </Button>
      </form>
      {error ? (
        <p role="alert" className="text-body-sm text-danger-text">
          {error.message}
          {/* QC-014: first-order / segment coupons need an account. */}
          {error.code === "coupon_sign_in_required" ? (
            <>
              {" "}
              <button type="button" onClick={toLogin} className="text-link underline">
                {t("checkout.signIn")}
              </button>
            </>
          ) : null}
        </p>
      ) : null}
      {cart.coupons.map((c) => (
        <p key={c.code} className="flex items-center gap-2 text-body-sm text-success-text">
          <Check className="size-4" />
          <span className="flex-1">{t("cart.couponApplied", { code: c.code })}</span>
          <button type="button" onClick={() => remove.mutate(c.code)} aria-label={`${t("common.remove")} ${c.code}`} className="text-secondary hover:text-ink">
            <X className="size-4" />
          </button>
        </p>
      ))}
    </div>
  );
}

/**
 * `data.warnings` (localised strings from the API): a deal over its limit, a
 * removed warranty plan, or a coupon that is kept on the cart but no longer
 * applies (QC-014).
 */
export function CartWarnings({ cart, className }: { cart: Cart; className?: string }) {
  const t = useTranslations("cart");
  const warnings = cart.warnings.filter((w): w is string => typeof w === "string" && w.trim() !== "");
  if (cart.warnings.length === 0) return null;
  return (
    <Alert tone="warning" className={className}>
      {warnings.length === 0 ? (
        t("warning")
      ) : (
        <>
          <p className="font-semibold">{t("warningsTitle")}</p>
          <ul className="mt-1 list-disc ps-5">
            {warnings.map((w) => (
              <li key={w}>{w}</li>
            ))}
          </ul>
        </>
      )}
    </Alert>
  );
}
