"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { ArrowLeft, Scale } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/account-extra";
import { getOrders } from "@/lib/api/endpoints";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { ExtraStatus, fieldErrors, formError, Thread } from "./shared";

type Reason = (typeof api.DISPUTE_REASONS)[number];

function useReasonLabel() {
  const t = useTranslations("accountExtra.disputes");
  return (reason: string) => (api.DISPUTE_REASONS.includes(reason as Reason) ? t(`reason.${reason as Reason}`) : reason);
}

/** `/account/disputes` (Figma Account disputes 49:1706). */
export function DisputesList() {
  const t = useTranslations("accountExtra.disputes");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const reasonLabel = useReasonLabel();
  const { data, isLoading, error } = useQuery({ queryKey: ["disputes", market.slug], queryFn: async () => (await api.getDisputes(market.slug)).data });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <ButtonLink href={href("/account/disputes/new")}>{t("open")}</ButtonLink>
      </div>
      {isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {data && data.length === 0 ? <EmptyState icon={<Scale className="size-10" />} title={t("empty")} body={t("emptyHint")} /> : null}
      {data?.map((d) => (
        <Card key={d.number}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="flex flex-col gap-1">
              <ExtraStatus status={d.status} />
              <p className="text-body font-semibold">{reasonLabel(d.reason)}</p>
              <p className="text-body-sm text-secondary">
                {d.number} · {t("shipmentLine", { shipment: d.shipment ?? "—", date: formatDay(d.created_at, market.locale) })}
              </p>
              {d.refund ? <p className="text-body-sm font-semibold text-success-text">{t("refund", { amount: formatMoney(d.refund) })}</p> : null}
            </div>
            <ButtonLink variant="outline" size="sm" href={href(`/account/disputes/${encodeURIComponent(d.number)}`)}>
              {t("view")}
            </ButtonLink>
          </div>
          <DisputeSteps status={d.status} createdAt={d.created_at} />
        </Card>
      ))}
    </div>
  );
}

const STEP_INDEX: Record<string, number> = { open: 0, seller_responded: 1, under_review: 2, escalated: 2, resolved: 3, closed: 3 };

function DisputeSteps({ status, createdAt }: { status: string; createdAt: string }) {
  const t = useTranslations("accountExtra.disputes.steps");
  const market = useMarket();
  const current = STEP_INDEX[status] ?? 0;
  const steps = ["opened", "responded", "review", "resolved"] as const;
  return (
    <ol className="mt-4 grid grid-cols-4 gap-2">
      {steps.map((step, i) => (
        <li key={step} className="flex flex-col gap-1">
          <span className={clsx("h-1 rounded-full", i <= current ? (i === current && i < 3 ? "bg-brand" : "bg-success") : "bg-line")} />
          <span className={clsx("text-caption font-medium", i <= current ? "text-ink" : "text-muted")}>{t(step)}</span>
          {i === 0 ? <span className="text-caption text-secondary">{formatDay(createdAt, market.locale)}</span> : null}
        </li>
      ))}
    </ol>
  );
}

/** `/account/disputes/new` (Figma Open dispute 105:7009). `?shipment=` preselects a shipment. */
export function NewDispute() {
  const t = useTranslations("accountExtra.disputes");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const params = useSearchParams();
  const client = useQueryClient();
  const reasonLabel = useReasonLabel();
  const [shipment, setShipment] = useState(params.get("shipment") ?? "");
  const [reason, setReason] = useState<Reason>("not_as_described");
  const [message, setMessage] = useState("");
  const orders = useQuery({ queryKey: ["orders", market.slug, 1], queryFn: async () => (await getOrders(market.slug, 1)).data });
  const shipments = (orders.data ?? []).flatMap((order) =>
    order.shipments.filter((s) => s.status !== "cancelled").map((s) => ({ number: s.number, label: `${s.number} · ${s.items.map((i) => i.title).join(", ")}`.slice(0, 120) })),
  );
  const open = useMutation({
    mutationFn: async () => (await api.openDispute(market.slug, { shipment, reason, message: message.trim() })).data,
    onSuccess: (dispute) => {
      client.invalidateQueries({ queryKey: ["disputes", market.slug] });
      router.push(href(`/account/disputes/${encodeURIComponent(dispute.number)}`));
    },
  });
  const errors = fieldErrors(open.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    open.mutate();
  };

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/disputes")} className="flex items-center gap-1 text-body-sm text-link">
        <ArrowLeft className="size-4 rtl:rotate-180" />
        {t("back")}
      </Link>
      <h1 className="text-h2 font-bold">{t("newTitle")}</h1>
      <p className="text-body text-secondary">{t("newHint")}</p>
      <Card>
        {orders.isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {orders.data && shipments.length === 0 ? <p className="text-body text-secondary">{t("noShipments")}</p> : null}
        {shipments.length > 0 ? (
          <form onSubmit={submit} className="flex flex-col gap-4">
            <Field label={t("shipment")} error={errors.shipment}>
              <Select value={shipment} onChange={(e) => setShipment(e.target.value)} required>
                <option value="">{t("chooseShipment")}</option>
                {shipments.map((s) => (
                  <option key={s.number} value={s.number}>
                    {s.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={t("reasonLabel")} error={errors.reason}>
              <Select value={reason} onChange={(e) => setReason(e.target.value as Reason)}>
                {api.DISPUTE_REASONS.map((r) => (
                  <option key={r} value={r}>
                    {reasonLabel(r)}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={t("message")} hint={t("messageHint")} error={errors.message}>
              <Textarea value={message} onChange={(e) => setMessage(e.target.value)} minLength={10} maxLength={2000} required />
            </Field>
            {formError(open.error, ["shipment", "reason", "message"], tc("error")) ? <Alert tone="danger">{formError(open.error, ["shipment", "reason", "message"], tc("error"))}</Alert> : null}
            <Button type="submit" size="lg" disabled={!shipment || message.trim().length < 10 || open.isPending} className="self-start">
              {t("submit")}
            </Button>
          </form>
        ) : null}
      </Card>
    </div>
  );
}

/** `/account/disputes/[number]` — status, steps and the thread. */
export function DisputeDetail({ number }: { number: string }) {
  const t = useTranslations("accountExtra.disputes");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const reasonLabel = useReasonLabel();
  const key = ["dispute", market.slug, number];
  const { data, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await api.getDispute(market.slug, number)).data });
  const resolution = data?.resolution as "split" | null | undefined;

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/disputes")} className="flex items-center gap-1 text-body-sm text-link">
        <ArrowLeft className="size-4 rtl:rotate-180" />
        {t("back")}
      </Link>
      {isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {data ? (
        <Card>
          <div className="flex flex-col gap-1">
            <ExtraStatus status={data.status} />
            <h1 className="text-h3 font-semibold">{reasonLabel(data.reason)}</h1>
            <p className="text-body-sm text-secondary">
              {t("number", { number: data.number })} · {t("shipmentLine", { shipment: data.shipment ?? "—", date: formatDay(data.created_at, market.locale) })}
            </p>
            {resolution ? <p className="text-body font-medium">{t.has(`resolution.${resolution}`) ? t(`resolution.${resolution}`) : resolution}</p> : null}
            {data.refund ? <p className="text-body font-semibold text-success-text">{t("refund", { amount: formatMoney(data.refund) })}</p> : null}
          </div>
          <DisputeSteps status={data.status} createdAt={data.created_at} />
          <div className="mt-4">
            <Thread
              messages={data.messages ?? []}
              onSend={async (body) => {
                const updated = (await api.postDisputeMessage(market.slug, number, body)).data;
                client.setQueryData(key, updated);
              }}
            />
          </div>
        </Card>
      ) : null}
    </div>
  );
}
