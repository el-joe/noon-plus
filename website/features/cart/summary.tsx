"use client";

import { Check, Tag, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent, type ReactNode } from "react";
import type { Cart } from "@/lib/api/schemas";
import * as api from "@/lib/api/endpoints";
import { formatMoney, isZero } from "@/lib/format";
import { errorMessage, useCartMutation } from "@/lib/hooks";
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
  const [error, setError] = useState<string | null>(null);
  const apply = useCartMutation((m, c: string) => api.applyCoupon(m, c));
  const remove = useCartMutation((m, c: string) => api.removeCoupon(m, c));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    apply.mutate(code.trim(), { onSuccess: () => setCode(""), onError: (e) => setError(errorMessage(e, t("common.error"))) });
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
      {error ? <p className="text-body-sm text-danger-text">{error}</p> : null}
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
