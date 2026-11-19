"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ChevronRight, Download } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ButtonLink } from "@/components/ui/button";
import { Alert, Card, ProductImage, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status-badge";
import { OrderTotalsCard } from "@/features/checkout/order-summary";
import * as api from "@/lib/api/endpoints";
import type { Order } from "@/lib/api/schemas";
import { formatDateTime, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { formatAddress } from "./address-form";
import { ShipmentActions, invoiceHref } from "./order-actions";
import { useShipmentHeadline } from "./orders";

const STEPS = ["placed", "confirmed", "packed", "shipped", "out_for_delivery", "delivered"] as const;

/**
 * `/account/orders/{number}` (Figma Order detail & tracking 34:1283): one card
 * per shipment with progress, the 4-digit delivery code to read to the courier
 * (ADR 15), cancel before shipping, return after delivery.
 */
export function OrderDetail({ number }: { number: string }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const key = ["order", market.slug, number];
  const { data: order, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await api.getOrder(market.slug, number)).data });
  const onUpdated = (next: Order) => client.setQueryData(key, next);

  if (isLoading) return <Skeleton className="h-96 w-full" />;
  if (error || !order) return <Alert tone="danger">{errorMessage(error, t("common.notFoundTitle"))}</Alert>;

  const payPending = order.payment?.next_action.type === "redirect" && order.payment.next_action.url;

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/orders")} className="flex items-center gap-1.5 text-body-sm text-secondary">
        <ArrowLeft className="size-4 rtl:rotate-180" /> {t("account.order.back")}
      </Link>
      <div>
        <h1 className="flex flex-wrap items-center gap-3 text-h2 font-bold">
          {t("account.order.title", { number: order.number })} <StatusBadge status={order.status} />
        </h1>
        <p className="text-body-sm text-secondary">{t("account.order.placedOn", { date: formatDateTime(order.placed_at, market.locale, market.country.timeZone) })}</p>
      </div>
      {payPending ? (
        <Alert tone="warning" className="flex flex-wrap items-center justify-between gap-3">
          <span>{t("payment.pendingTitle")}</span>
          <ButtonLink href={order.payment!.next_action.url!} size="sm">
            {t("account.order.payNow")}
          </ButtonLink>
        </Alert>
      ) : order.payment_status === "under_review" || order.payment?.status === "under_review" ? (
        <Alert tone="info">
          <span className="font-semibold">{t("payment.underReviewTitle")}</span> · {t("payment.underReviewHint")}
        </Alert>
      ) : order.payment?.next_action.type === "retry" ? (
        <Alert tone="danger" className="flex flex-wrap items-center justify-between gap-3">
          <span>{t("payment.failedTitle")}</span>
          <ButtonLink href={href(`/checkout/payment?order=${encodeURIComponent(order.number)}`)} size="sm">
            {t("payment.retry")}
          </ButtonLink>
        </Alert>
      ) : null}
      {order.status === "disputed" ? (
        <Alert tone="warning" className="flex flex-wrap items-center justify-between gap-3">
          <span>{t("account.order.disputeOpen")}</span>
          <ButtonLink href={href("/account/disputes")} size="sm" variant="outline">
            {t("account.order.viewDisputes")}
          </ButtonLink>
        </Alert>
      ) : null}
      {order.payment?.status === "voided" ? <Alert tone="info">{t("account.order.paymentVoided")}</Alert> : null}

      <div className="grid items-start gap-4 xl:grid-cols-[1fr_320px]">
        <div className="flex flex-col gap-4">
          {order.shipments.map((s, i) => (
            <Shipment key={s.number} order={order} shipment={s} index={i} onUpdated={onUpdated} />
          ))}
          <p className="text-body-sm text-secondary">{t("account.order.cancelHint")}</p>
        </div>
        <aside className="flex flex-col gap-4">
          {order.shipping_address ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("account.order.address")}</h2>
              <p className="text-body-sm">
                {order.shipping_address.name}
                {order.shipping_address.phone ? ` · ${order.shipping_address.phone}` : ""}
              </p>
              <p className="text-body-sm text-secondary">{formatAddress(order.shipping_address)}</p>
            </Card>
          ) : null}
          <OrderTotalsCard order={order} />
          <Card>
            <h2 className="mb-2 text-h4 font-semibold">{t("account.order.help")}</h2>
            <Link href={href("/account/returns")} className="flex items-center justify-between py-1.5 text-body-sm text-link">
              {t("account.order.requestReturn")} <ChevronRight className="size-4 rtl:rotate-180" />
            </Link>
            {order.invoice_available ? (
              <>
                <a href={invoiceHref(market.slug, order.number)} target="_blank" rel="noopener" className="flex items-center justify-between py-1.5 text-body-sm text-link">
                  {t("accountPlus.orders.invoice")} <ChevronRight className="size-4 rtl:rotate-180" />
                </a>
                <a href={invoiceHref(market.slug, order.number, "pdf")} download className="flex items-center justify-between py-1.5 text-body-sm text-link">
                  {t("accountPlus.orders.invoicePdf")} <Download className="size-4" />
                </a>
              </>
            ) : null}
            <Link href={href(`/account/support/new?order=${encodeURIComponent(order.number)}`)} className="flex items-center justify-between py-1.5 text-body-sm text-link">
              {t("accountPlus.support.contact")} <ChevronRight className="size-4 rtl:rotate-180" />
            </Link>
          </Card>
        </aside>
      </div>
    </div>
  );
}

export function Shipment({ order, shipment, index, onUpdated }: { order: Order; shipment: Order["shipments"][number]; index: number; onUpdated: (order: Order) => void }) {
  const t = useTranslations();
  const headline = useShipmentHeadline();
  const line = headline(shipment);
  const reached = STEPS.indexOf(shipment.status as (typeof STEPS)[number]);
  const cancelled = shipment.status === "cancelled";

  return (
    <Card>
      <div id={`shipment-${shipment.number}`} className="flex scroll-mt-24 flex-wrap items-start justify-between gap-4">
        <div>
          <StatusBadge status={shipment.status} />
          {line ? <h2 className="mt-1 text-h3 font-semibold">{line}</h2> : null}
          <p className="text-body-sm text-secondary">
            {t("account.order.shipment", { n: index + 1, total: order.shipments.length })} · {t("account.order.soldBy", { seller: shipment.seller ?? "Nawy" })}
            {shipment.fulfilled_by === "nawy" ? (
              <span data-fulfilled-by="nawy"> · {t("account.order.fulfilledByNawy")}</span>
            ) : null}
            {shipment.method ? ` · ${shipment.method}` : ""}
          </p>
        </div>
        {shipment.delivery_code ? (
          <div className="rounded-lg border border-brand bg-brand-subtle px-5 py-3 text-center">
            <p className="text-caption font-medium uppercase text-brand-text">{t("account.order.deliveryCode")}</p>
            <p className="text-[30px] font-bold tracking-[0.3em] tabular-nums" dir="ltr">
              {shipment.delivery_code}
            </p>
            <p className="text-caption text-secondary">{t("account.order.readToCourier")}</p>
          </div>
        ) : null}
      </div>

      {!cancelled ? (
        <ol className="my-4 grid grid-cols-6 gap-1" aria-label={t("common.progress")}>
          {STEPS.map((step, i) => (
            <li key={step} className="flex flex-col gap-1.5">
              <span className={`h-1.5 rounded-full ${i <= reached ? "bg-success" : "bg-subtle"}`} />
              <span className={`text-caption ${i <= reached ? "text-ink" : "text-muted"}`}>{t.has(`status.${step}`) ? t(`status.${step}`) : step}</span>
            </li>
          ))}
        </ol>
      ) : null}

      <ul className="flex flex-col gap-3">
        {shipment.items.map((item) => (
          <li key={item.id} className="flex items-center gap-3">
            <div className="size-14 shrink-0 overflow-hidden rounded-md border border-line">
              <ProductImage src={item.image?.url} alt={item.image?.alt || item.title} />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-body font-medium">{item.title}</p>
              <p className="text-body-sm text-secondary">{t("account.order.qty", { qty: item.quantity, price: formatMoney(item.unit_price) })}</p>
            </div>
          </li>
        ))}
      </ul>

      <div className="mt-4">
        <ShipmentActions order={order} shipment={shipment} onUpdated={onUpdated} hide={["track"]} />
      </div>
    </Card>
  );
}
