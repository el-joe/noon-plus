"use client";

import { useQuery } from "@tanstack/react-query";
import { ChevronRight, Wallet } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { formatMoney } from "@/lib/format";
import { useHref, useMarket } from "@/lib/market-context";
import { OrderRow, useOrders } from "./orders";

/** `/account` overview (Figma 33:134). */
export function AccountOverview() {
  const t = useTranslations("account");
  const market = useMarket();
  const href = useHref();
  const orders = useOrders(1);
  const wallet = useQuery({ queryKey: ["wallet", market.slug], retry: false, queryFn: async () => (await api.getWallet(market.slug)).data });

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("nav.overview")}</h1>
      <Link href={href("/account/credits")} className="flex items-center gap-4 rounded-lg bg-ink p-5 text-white">
        <Wallet className="size-8 text-brand" />
        <div className="flex-1">
          <p className="text-body-sm opacity-80">{t("overviewCredits")}</p>
          <p className="text-h2 font-bold">{wallet.data ? formatMoney(wallet.data.available) : "—"}</p>
        </div>
        <ChevronRight className="size-5 rtl:rotate-180" />
      </Link>
      <Card>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-h4 font-semibold">{t("overviewOrders")}</h2>
          <Link href={href("/account/orders")} className="text-body-sm text-link">
            {t("orders.title")} ›
          </Link>
        </div>
        {orders.isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {orders.data && orders.data.data.length === 0 ? <p className="text-body text-secondary">{t("noOrders")}</p> : null}
        <div className="flex flex-col gap-3">
          {orders.data?.data.slice(0, 3).map((order) => <OrderRow key={order.number} order={order} />)}
        </div>
      </Card>
    </div>
  );
}
