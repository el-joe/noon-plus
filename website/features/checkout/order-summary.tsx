"use client";

import { useTranslations } from "next-intl";
import { Card } from "@/components/ui/misc";
import { Row } from "@/features/cart/summary";
import type { Order } from "@/lib/api/schemas";
import { formatDay, formatMoney, isZero } from "@/lib/format";
import { useMarket } from "@/lib/market-context";

/** Shipments + payment totals of a placed order (confirmation & order detail). */
export function OrderShipmentsCard({ order }: { order: Order }) {
  const t = useTranslations("confirmation");
  const market = useMarket();
  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("shipments", { count: order.shipments.length })}</h2>
      <ul className="flex flex-col divide-y divide-line">
        {order.shipments.map((s, i) => (
          <li key={s.number} className="flex flex-col gap-1 py-3">
            <p className="text-body-sm text-secondary">{t("shipmentLine", { n: i + 1, seller: s.seller ?? "Nawy" })}</p>
            {s.items.map((item) => (
              <p key={item.id} className="text-body font-medium">
                {item.title} × {item.quantity}
              </p>
            ))}
            {s.delivery.by ? (
              <p className="text-body-sm text-success-text">
                {s.delivery.from && s.delivery.from !== s.delivery.by
                  ? t("arrivesBetween", { from: formatDay(s.delivery.from, market.locale), to: formatDay(s.delivery.by, market.locale) })
                  : t("arrives", { date: formatDay(s.delivery.by, market.locale, true) })}
              </p>
            ) : null}
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function OrderTotalsCard({ order }: { order: Order }) {
  const t = useTranslations();
  const totals = order.totals;
  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("confirmation.paymentTitle")}</h2>
      <div className="flex flex-col gap-2">
        <Row label={t("cart.subtotal")} value={formatMoney(totals.subtotal)} />
        <Row label={t("cart.shipping")} value={isZero(totals.shipping) ? t("common.free") : formatMoney(totals.shipping)} tone={isZero(totals.shipping) ? "success" : undefined} />
        {!isZero(totals.discount) ? <Row label={t("cart.discount")} value={`− ${formatMoney(totals.discount)}`} tone="success" /> : null}
        {!isZero(totals.warranty) ? <Row label={t("cart.warranty")} value={formatMoney(totals.warranty)} /> : null}
        {!isZero(totals.cod_fee) ? <Row label={t("cart.codFee")} value={formatMoney(totals.cod_fee)} /> : null}
        {!isZero(totals.tax_included) ? <Row label={t("cart.vat")} value={formatMoney(totals.tax_included)} /> : null}
        <div className="mt-1 flex items-center justify-between border-t border-line pt-3">
          <span className="text-body">{order.payment_method ? t("confirmation.paidWith", { method: order.payment_method.toUpperCase() }) : t("cart.total")}</span>
          <span className="text-h3 font-bold">{formatMoney(totals.total)}</span>
        </div>
      </div>
    </Card>
  );
}
