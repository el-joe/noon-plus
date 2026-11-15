"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Alert, Card, Skeleton } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { formatDateTime, formatDay, formatMoney, humanize } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { ExtraStatus } from "./shared";

const REASONS = ["defective", "damaged_on_arrival", "wrong_item", "not_as_described", "changed_mind", "size_or_fit", "other"] as const;
const METHODS = ["pickup", "drop_off"] as const;
const DESTINATIONS = ["original", "wallet"] as const;

/** `/account/returns/[number]` — return detail: timeline, items, refund (extends Figma 49:1155). */
export function ReturnDetail({ number }: { number: string }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const key = ["return", market.slug, number];
  const { data, isLoading, error } = useQuery({ queryKey: key, retry: false, queryFn: async () => (await api.getReturn(market.slug, number)).data });
  const cancel = useMutation({
    mutationFn: async () => (await api.cancelReturn(market.slug, number)).data,
    onSuccess: (updated) => {
      client.setQueryData(key, updated);
      client.invalidateQueries({ queryKey: ["returns", market.slug] });
    },
  });
  const reason = (r: string) => (REASONS.includes(r as (typeof REASONS)[number]) ? t(`account.returns.reason.${r as (typeof REASONS)[number]}`) : humanize(r));
  const method = (m: string) => (METHODS.includes(m as (typeof METHODS)[number]) ? t(`account.returns.method.${m as (typeof METHODS)[number]}`) : humanize(m));
  const destination = (d: string) => (DESTINATIONS.includes(d as (typeof DESTINATIONS)[number]) ? t(`accountExtra.returnDetail.destination.${d as (typeof DESTINATIONS)[number]}`) : humanize(d));
  const outcome = (o: string) => (t.has(`accountExtra.status.${o as "approved"}`) ? t(`accountExtra.status.${o as "approved"}`) : humanize(o));

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/returns")} className="flex items-center gap-1 text-body-sm text-link">
        <ArrowLeft className="size-4 rtl:rotate-180" />
        {t("accountExtra.returnDetail.back")}
      </Link>
      {isLoading ? <Skeleton className="h-72 w-full" /> : null}
      {error ? (
        <Alert tone="danger">{error instanceof ApiError && error.status === 404 ? t("accountExtra.returnDetail.notFound") : errorMessage(error, t("common.error"))}</Alert>
      ) : null}
      {data ? (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="flex flex-wrap items-center gap-2 text-h2 font-bold">
                {t("accountExtra.returnDetail.title", { number: data.number })} <ExtraStatus status={data.status} />
              </h1>
              <p className="text-body-sm text-secondary">
                {t("accountExtra.returnDetail.requested", { date: formatDay(data.created_at, market.locale) })}
                {data.shipment ? ` · ${data.shipment}` : ""}
              </p>
            </div>
            {data.status === "requested" ? (
              <Button variant="outline" onClick={() => cancel.mutate()} disabled={cancel.isPending}>
                {t("accountExtra.returnDetail.cancel")}
              </Button>
            ) : null}
          </div>
          {cancel.error ? <Alert tone="danger">{errorMessage(cancel.error, t("common.error"))}</Alert> : null}
          {data.rejection_reason ? (
            <Alert tone="danger">
              <span className="font-semibold">{t("accountExtra.returnDetail.rejected")}: </span>
              {data.rejection_reason}
            </Alert>
          ) : null}

          <div className="grid items-start gap-4 lg:grid-cols-[1fr_320px]">
            <div className="flex flex-col gap-4">
              <Card>
                <h2 className="mb-3 text-h4 font-semibold">{t("accountExtra.returnDetail.timeline")}</h2>
                <ol className="flex flex-col">
                  {data.timeline.map((step, i) => (
                    <li key={`${step.status}-${i}`} className="relative flex gap-3 pb-4 last:pb-0">
                      <span className={clsx("mt-1 size-3 shrink-0 rounded-full", i === data.timeline.length - 1 ? "bg-brand" : "bg-success")} />
                      {i < data.timeline.length - 1 ? <span className="absolute start-[5px] top-4 h-full w-0.5 bg-line" aria-hidden /> : null}
                      <div>
                        <p className="text-body font-medium">{outcome(step.status)}</p>
                        <p className="text-body-sm text-secondary">{formatDateTime(step.at, market.locale, market.country.timeZone)}</p>
                      </div>
                    </li>
                  ))}
                </ol>
              </Card>
              <Card>
                <h2 className="mb-3 text-h4 font-semibold">{t("accountExtra.returnDetail.items")}</h2>
                <ul className="divide-y divide-line">
                  {data.items.map((line) => (
                    <li key={line.order_item_id} className="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0">
                      <div>
                        <p className="text-body font-medium">
                          {line.title} × {line.quantity}
                        </p>
                        {line.paid ? <p className="text-body-sm text-secondary">{t("accountExtra.returnDetail.paid", { amount: formatMoney(line.paid) })}</p> : null}
                        {line.outcome ? <p className="text-body-sm">{t("accountExtra.returnDetail.outcome", { outcome: outcome(line.outcome) })}</p> : null}
                      </div>
                      {line.refund ? <p className="text-body font-semibold text-success-text">{formatMoney(line.refund)}</p> : null}
                    </li>
                  ))}
                </ul>
              </Card>
            </div>
            <div className="flex flex-col gap-4">
              {data.refund ? (
                <Card>
                  <h2 className="mb-2 flex items-center justify-between gap-2 text-h4 font-semibold">
                    {t("accountExtra.returnDetail.refund")} <ExtraStatus status={data.refund.status} />
                  </h2>
                  <p className="text-body">
                    {t("accountExtra.returnDetail.refundLine", { number: data.refund.number, amount: formatMoney(data.refund.amount), destination: destination(data.refund.destination) })}
                  </p>
                </Card>
              ) : null}
              <Card>
                <h2 className="mb-2 text-h4 font-semibold">{t("accountExtra.returnDetail.details")}</h2>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-body-sm">
                  <dt className="text-secondary">{t("accountExtra.returnDetail.reason")}</dt>
                  <dd>{reason(data.reason)}</dd>
                  <dt className="text-secondary">{t("accountExtra.returnDetail.method")}</dt>
                  <dd>{method(data.method)}</dd>
                  <dt className="text-secondary">{t("accountExtra.returnDetail.refundTo")}</dt>
                  <dd>{destination(data.refund_destination)}</dd>
                  {data.comment ? (
                    <>
                      <dt className="text-secondary">{t("accountExtra.returnDetail.comment")}</dt>
                      <dd className="whitespace-pre-line">{data.comment}</dd>
                    </>
                  ) : null}
                </dl>
                {data.photos.length > 0 ? (
                  <div className="mt-3">
                    <p className="mb-2 text-body-sm text-secondary">{t("accountExtra.returnDetail.photos")}</p>
                    <div className="flex flex-wrap gap-2">
                      {data.photos.filter((p) => /^(https?:)?\//.test(p)).map((photo) => (
                        <a key={photo} href={photo} target="_blank" rel="noreferrer" className="size-16 overflow-hidden rounded-md border border-line">
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img src={photo} alt="" className="size-full object-cover" />
                        </a>
                      ))}
                    </div>
                  </div>
                ) : null}
              </Card>
            </div>
          </div>
        </>
      ) : null}
    </div>
  );
}
