"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Alert, Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import { formatDateTime, formatDay, formatMoney, humanize } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/** `/account/credits` — Nawy Credits incl. promotional (Figma 51:3692). */
export function CreditsView() {
  const t = useTranslations();
  const market = useMarket();
  const [page, setPage] = useState(1);
  const wallet = useQuery({ queryKey: ["wallet", market.slug], queryFn: async () => (await api.getWallet(market.slug)).data });
  const history = useQuery({ queryKey: ["wallet-tx", market.slug, page], queryFn: async () => api.getWalletTransactions(market.slug, page) });
  const pages = Math.max(1, Math.ceil(Number(history.data?.meta.total ?? 0) / 30));

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.credits.title")}</h1>
      {wallet.error ? <Alert tone="danger">{errorMessage(wallet.error, t("common.error"))}</Alert> : null}
      {wallet.isLoading ? <Skeleton className="h-36 w-full" /> : null}
      {wallet.data ? (
        <div className="grid gap-3 md:grid-cols-4">
          <div className="rounded-lg bg-ink p-5 text-white md:col-span-1">
            <p className="text-body-sm opacity-80">{t("account.credits.available")}</p>
            <p className="text-h2 font-bold">{formatMoney(wallet.data.available)}</p>
          </div>
          {(["spendable", "promotional", "frozen"] as const).map((bucket) => (
            <Card key={bucket}>
              <p className="text-body-sm text-secondary">{t(`account.credits.${bucket}`)}</p>
              <p className="text-h3 font-semibold">{formatMoney(wallet.data[bucket])}</p>
            </Card>
          ))}
        </div>
      ) : null}
      {wallet.data && wallet.data.promotional_expiring.length > 0 ? (
        <Card>
          <h2 className="mb-2 text-h4 font-semibold">{t("account.credits.expiring")}</h2>
          <ul className="flex flex-col gap-1 text-body-sm">
            {wallet.data.promotional_expiring.map((credit, i) => (
              <li key={i}>{t("account.credits.expiresOn", { amount: formatMoney(credit.amount), date: formatDay(credit.expires_at, market.locale) })}</li>
            ))}
          </ul>
        </Card>
      ) : null}
      <Card className="p-0 md:p-0">
        <h2 className="border-b border-line px-5 py-3 text-h4 font-semibold">{t("account.credits.history")}</h2>
        {history.isLoading ? <Skeleton className="m-5 h-32" /> : null}
        {history.data && history.data.data.length === 0 ? <p className="px-5 py-6 text-body text-secondary">{t("account.credits.empty")}</p> : null}
        <ul className="divide-y divide-line">
          {history.data?.data.map((entry) => (
            <li key={entry.id} className="flex items-center justify-between gap-4 px-5 py-3">
              <div>
                <p className="text-body font-medium">{entry.description ?? humanize(entry.type ?? "")}</p>
                <p className="text-body-sm text-secondary">
                  {formatDateTime(entry.at, market.locale, market.country.timeZone)}
                  {entry.bucket ? ` · ${humanize(entry.bucket)}` : ""}
                </p>
              </div>
              <p className={clsx("text-body font-semibold tabular-nums", entry.direction === "in" ? "text-success-text" : "text-ink")}>
                {entry.direction === "in" ? "+" : "−"} {formatMoney(entry.amount)}
              </p>
            </li>
          ))}
        </ul>
        {pages > 1 ? (
          <div className="flex items-center justify-center gap-4 border-t border-line p-3 text-body">
            <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="text-link disabled:text-muted">
              {t("listing.prev")}
            </button>
            <span className="text-body-sm text-secondary">{t("listing.page", { page, last: pages })}</span>
            <button type="button" disabled={page >= pages} onClick={() => setPage(page + 1)} className="text-link disabled:text-muted">
              {t("listing.next")}
            </button>
          </div>
        ) : null}
      </Card>
    </div>
  );
}
