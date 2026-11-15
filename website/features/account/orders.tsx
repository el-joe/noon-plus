"use client";

import { useQuery } from "@tanstack/react-query";
import { Package } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Alert, Card, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status-badge";
import * as api from "@/lib/api/endpoints";
import type { Order } from "@/lib/api/schemas";
import { formatDateTime, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

export function useOrders(page = 1) {
  const market = useMarket();
  return useQuery({
    queryKey: ["orders", market.slug, page],
    queryFn: async () => api.getOrders(market.slug, page),
  });
}

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

/** `/account/orders` (Figma 33:658). */
export function OrdersList() {
  const t = useTranslations();
  const [page, setPage] = useState(1);
  const { data, isLoading, error } = useOrders(page);
  const total = Number(data?.meta.total ?? 0);
  const pages = Math.max(1, Math.ceil(total / 20));

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.orders.title")}</h1>
      {isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, t("common.error"))}</Alert> : null}
      {data && data.data.length === 0 ? <EmptyState icon={<Package className="size-10" />} title={t("account.noOrders")} /> : null}
      <div className="flex flex-col gap-3">
        {data?.data.map((order) => <OrderRow key={order.number} order={order} />)}
      </div>
      {pages > 1 ? (
        <Card className="flex items-center justify-center gap-4 p-3 md:p-3">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="text-body text-link disabled:text-muted">
            {t("listing.prev")}
          </button>
          <span className="text-body-sm text-secondary">{t("listing.page", { page, last: pages })}</span>
          <button type="button" disabled={page >= pages} onClick={() => setPage(page + 1)} className="text-body text-link disabled:text-muted">
            {t("listing.next")}
          </button>
        </Card>
      ) : null}
    </div>
  );
}
