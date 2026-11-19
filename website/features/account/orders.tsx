"use client";

import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { Package, Search } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status-badge";
import * as api from "@/lib/api/endpoints";
import type { OrderTab } from "@/lib/api/endpoints";
import type { Order } from "@/lib/api/schemas";
import { formatDateTime, formatDay, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { ShipmentActions } from "./order-actions";

const PER_PAGE = 10;

export function useOrders(page = 1) {
  const market = useMarket();
  return useQuery({
    queryKey: ["orders", market.slug, "page", page],
    queryFn: async () => api.getOrders(market.slug, page),
  });
}

/** Compact order link (account overview). */
export function OrderRow({ order }: { order: Order }) {
  const t = useTranslations("account.orders");
  const market = useMarket();
  const href = useHref();
  const items = order.shipments.flatMap((s) => s.items);
  return (
    <Link href={href(`/account/orders/${encodeURIComponent(order.number)}`)} className="flex flex-wrap items-center gap-4 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
      <span className="flex size-12 items-center justify-center overflow-hidden rounded-md bg-subtle text-muted">
        {items.find((i) => i.image)?.image ? <ProductImage src={items.find((i) => i.image)!.image!.url} alt="" /> : <Package className="size-6" />}
      </span>
      <div className="min-w-0 flex-1">
        <p className="flex flex-wrap items-center gap-2 text-body font-semibold">
          {order.number} <StatusBadge status={order.status} />
        </p>
        <p className="truncate text-body-sm text-secondary">{items.map((i) => i.title).join(" · ")}</p>
        <p className="text-body-sm text-secondary">
          {t("placed", { date: formatDateTime(order.placed_at, market.locale, market.country.timeZone) })} · {t("shipmentsCount", { count: order.shipments.length })}
        </p>
      </div>
      <div className="text-end">
        <p className="text-body font-semibold">{formatMoney(order.totals.total)}</p>
        <p className="text-body-sm text-link">{t("view")}</p>
      </div>
    </Link>
  );
}

/** The headline of a shipment: arriving, delivered (+ return window) or cancelled. */
export function useShipmentHeadline() {
  const t = useTranslations("accountPlus.orders");
  const to = useTranslations("account.order");
  const market = useMarket();
  return (shipment: Order["shipments"][number]): string | null => {
    // QC-034: a delivered shipment an admin force-cancels shows as cancelled.
    if (shipment.status === "cancelled") return t("cancelledHeadline");
    if (shipment.status === "returned" || shipment.status === "refunded") return null;
    if (shipment.delivered_at || shipment.status === "delivered" || shipment.status === "completed") {
      const delivered = shipment.delivered_at ? formatDay(shipment.delivered_at, market.locale) : null;
      if (delivered && shipment.return_until) return t("deliveredReturnUntil", { date: delivered, until: formatDay(shipment.return_until, market.locale) });
      return delivered ? t("deliveredOn", { date: delivered }) : t("delivered");
    }
    if (!shipment.delivery.by) return null;
    return shipment.delivery.from && shipment.delivery.from !== shipment.delivery.by
      ? to("arrivingBetween", { from: formatDay(shipment.delivery.from, market.locale), to: formatDay(shipment.delivery.by, market.locale) })
      : to("arriving", { date: formatDay(shipment.delivery.by, market.locale, true) });
  };
}

function OrderCard({ order }: { order: Order }) {
  const t = useTranslations("accountPlus.orders");
  const to = useTranslations("account.order");
  const market = useMarket();
  const href = useHref();
  const headline = useShipmentHeadline();

  return (
    <article className="overflow-hidden rounded-lg border border-line bg-surface">
      <header className="flex flex-wrap items-center gap-x-8 gap-y-2 bg-subtle px-4 py-3">
        <Meta label={t("order")} value={order.number} />
        <Meta label={t("placed")} value={formatDay(order.placed_at, market.locale)} />
        <Meta label={t("total")} value={formatMoney(order.totals.total)} />
        <Meta label={t("shipments")} value={String(order.shipments.length)} />
        {order.status === "disputed" ? <StatusBadge status="disputed" /> : null}
        <Link href={href(`/account/orders/${encodeURIComponent(order.number)}`)} className="ms-auto text-body-sm font-semibold text-link hover:underline">
          {t("details")}
        </Link>
      </header>
      <ul className="divide-y divide-line">
        {order.shipments.map((shipment, i) => {
          const line = headline(shipment);
          return (
            <li key={shipment.number} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
              <div className="flex shrink-0 gap-2">
                {shipment.items.slice(0, 3).map((item) => (
                  <span key={item.id} className="size-14 overflow-hidden rounded-md border border-line">
                    <ProductImage src={item.image?.url} alt={item.image?.alt || item.title} />
                  </span>
                ))}
                {shipment.items.length > 3 ? <span className="flex size-14 items-center justify-center rounded-md bg-subtle text-body-sm text-secondary">+{shipment.items.length - 3}</span> : null}
              </div>
              <div className="min-w-0 flex-1">
                <StatusBadge status={shipment.status} />
                {line ? <p className="mt-1 text-body font-semibold">{line}</p> : null}
                <p className="truncate text-body-sm text-secondary">
                  {to("shipment", { n: i + 1, total: order.shipments.length })} · {to("soldBy", { seller: shipment.seller ?? "Nawy" })}
                </p>
                <p className="truncate text-caption text-muted">{shipment.items.map((item) => item.title).join(" · ")}</p>
              </div>
              <ShipmentActions order={order} shipment={shipment} />
            </li>
          );
        })}
      </ul>
    </article>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-caption uppercase text-muted">{label}</p>
      <p className="text-body-sm font-semibold">{value}</p>
    </div>
  );
}

/** `/account/orders` (Figma 33:658, QC-026): tabs with counts, search and load more. */
export function OrdersList() {
  const t = useTranslations("accountPlus.orders");
  const tc = useTranslations();
  const market = useMarket();
  const [tab, setTab] = useState<OrderTab>("all");
  const [typed, setTyped] = useState("");
  const [q, setQ] = useState("");

  useEffect(() => {
    const id = window.setTimeout(() => setQ(typed.trim()), 350);
    return () => window.clearTimeout(id);
  }, [typed]);

  const list = useInfiniteQuery({
    queryKey: ["orders", market.slug, "list", tab, q],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => api.getOrders(market.slug, pageParam, { tab, q: q || undefined, per_page: PER_PAGE }),
    getNextPageParam: (last) => {
      const page = Number(last.meta.page ?? 1);
      const lastPage = Number(last.meta.last_page ?? 1);
      return page < lastPage ? page + 1 : undefined;
    },
  });

  const first = list.data?.pages[0];
  const counts = (first?.meta.counts ?? {}) as Partial<Record<OrderTab, number>>;
  const orders = list.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{tc("account.orders.title")}</h1>
      <div role="tablist" aria-label={tc("account.orders.title")} className="scrollbar-none flex gap-1 overflow-x-auto border-b border-line">
        {api.ORDER_TABS.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={clsx("-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-body", tab === key ? "border-brand font-semibold" : "border-transparent text-secondary hover:text-ink")}
          >
            {t(`tabs.${key}`)}
            {typeof counts[key] === "number" ? <span className="rounded-full bg-subtle px-1.5 text-caption text-secondary">{counts[key]}</span> : null}
          </button>
        ))}
      </div>
      <label className="relative block max-w-md">
        <span className="sr-only">{t("searchLabel")}</span>
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <input
          type="search"
          value={typed}
          onChange={(e) => setTyped(e.target.value)}
          maxLength={100}
          placeholder={t("searchPlaceholder")}
          className="h-10 w-full rounded-md border border-line bg-surface ps-9 pe-3 text-body outline-none focus:border-[var(--color-border-focus)]"
        />
      </label>
      {list.isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {list.error ? <Alert tone="danger">{errorMessage(list.error, tc("common.error"))}</Alert> : null}
      {list.data && orders.length === 0 ? (
        <EmptyState icon={<Package className="size-10" />} title={q ? t("noMatches", { q }) : tab === "all" ? tc("account.noOrders") : t("emptyTab")} />
      ) : null}
      <div className="flex flex-col gap-4">
        {orders.map((order) => (
          <OrderCard key={order.number} order={order} />
        ))}
      </div>
      {list.hasNextPage ? (
        <div className="flex justify-center">
          <Button variant="outline" onClick={() => list.fetchNextPage()} disabled={list.isFetchingNextPage}>
            {list.isFetchingNextPage ? t("loading") : t("loadMore")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
