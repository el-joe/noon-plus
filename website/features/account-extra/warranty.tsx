"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ShieldCheck } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/account-extra";
import { getOrders } from "@/lib/api/endpoints";
import { formatDay } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { ExtraStatus, fieldErrors, formError, Thread } from "./shared";

const DELIVERED = new Set(["delivered", "completed"]);

function useLabels() {
  const t = useTranslations("accountExtra.warranty");
  return {
    coverage: (c: string) => (t.has(`coverage.${c as "standard"}`) ? t(`coverage.${c as "standard"}`) : c),
    outcome: (o: string) => (t.has(`outcome.${o as "repair"}`) ? t(`outcome.${o as "repair"}`) : o),
  };
}

/** `/account/warranty` (Figma Account warranty 49:2222). */
export function WarrantyList() {
  const t = useTranslations("accountExtra.warranty");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const labels = useLabels();
  const { data, isLoading, error } = useQuery({ queryKey: ["warranty-claims", market.slug], queryFn: async () => (await api.getWarrantyClaims(market.slug)).data });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <ButtonLink href={href("/account/warranty/new")}>{t("new")}</ButtonLink>
      </div>
      {isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {data && data.length === 0 ? <EmptyState icon={<ShieldCheck className="size-10" />} title={t("empty")} body={t("emptyHint")} /> : null}
      {data?.map((claim) => (
        <Card key={claim.number}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="flex flex-col gap-1">
              <ExtraStatus status={claim.status} />
              <p className="text-body font-semibold">{claim.item ?? claim.number}</p>
              <p className="text-body-sm text-secondary">
                {claim.number} · {labels.coverage(claim.coverage)} · {t("submittedOn", { date: formatDay(claim.created_at, market.locale) })}
              </p>
              {claim.outcome ? <p className="text-body-sm font-medium">{t("outcomeLabel", { outcome: labels.outcome(claim.outcome) })}</p> : null}
            </div>
            <ButtonLink variant="outline" size="sm" href={href(`/account/warranty/${encodeURIComponent(claim.number)}`)}>
              {t("view")}
            </ButtonLink>
          </div>
        </Card>
      ))}
    </div>
  );
}

/** `/account/warranty/new` (Figma Warranty claim 105:7433). `?item=` preselects an order item. */
export function NewWarrantyClaim() {
  const t = useTranslations("accountExtra.warranty");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const params = useSearchParams();
  const client = useQueryClient();
  const [item, setItem] = useState(params.get("item") ?? "");
  const [description, setDescription] = useState("");
  const orders = useQuery({ queryKey: ["orders", market.slug, 1], queryFn: async () => (await getOrders(market.slug, 1)).data });
  const items = (orders.data ?? []).flatMap((order) =>
    order.shipments.filter((s) => DELIVERED.has(s.status)).flatMap((s) => s.items.map((i) => ({ id: i.id, label: `${i.title} · ${order.number}` }))),
  );
  const submit = useMutation({
    mutationFn: async () => (await api.submitWarrantyClaim(market.slug, { order_item_id: Number(item), description: description.trim() })).data,
    onSuccess: (claim) => {
      client.invalidateQueries({ queryKey: ["warranty-claims", market.slug] });
      router.push(href(`/account/warranty/${encodeURIComponent(claim.number)}`));
    },
  });
  const errors = fieldErrors(submit.error);
  const general = formError(submit.error, ["order_item_id", "description"], tc("error"));
  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    submit.mutate();
  };

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/warranty")} className="flex items-center gap-1 text-body-sm text-link">
        <ArrowLeft className="size-4 rtl:rotate-180" />
        {t("back")}
      </Link>
      <h1 className="text-h2 font-bold">{t("newTitle")}</h1>
      <p className="text-body text-secondary">{t("newHint")}</p>
      <Card>
        {orders.isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {orders.data && items.length === 0 ? <p className="text-body text-secondary">{t("noItems")}</p> : null}
        {items.length > 0 ? (
          <form onSubmit={onSubmit} className="flex flex-col gap-4">
            <Field label={t("item")} error={errors.order_item_id}>
              <Select value={item} onChange={(e) => setItem(e.target.value)} required>
                <option value="">{t("chooseItem")}</option>
                {items.map((i) => (
                  <option key={i.id} value={i.id}>
                    {i.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label={t("description")} hint={t("descriptionHint")} error={errors.description}>
              <Textarea value={description} onChange={(e) => setDescription(e.target.value)} minLength={10} maxLength={2000} required />
            </Field>
            {general ? <Alert tone="danger">{general}</Alert> : null}
            <Button type="submit" size="lg" disabled={!item || description.trim().length < 10 || submit.isPending} className="self-start">
              {t("submit")}
            </Button>
          </form>
        ) : null}
      </Card>
    </div>
  );
}

/** `/account/warranty/[number]` — claim status and the three-party thread. */
export function WarrantyClaimDetail({ number }: { number: string }) {
  const t = useTranslations("accountExtra.warranty");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const labels = useLabels();
  const key = ["warranty-claim", market.slug, number];
  const { data, isLoading, error } = useQuery({ queryKey: key, queryFn: async () => (await api.getWarrantyClaim(market.slug, number)).data });

  return (
    <div className="flex flex-col gap-4">
      <Link href={href("/account/warranty")} className="flex items-center gap-1 text-body-sm text-link">
        <ArrowLeft className="size-4 rtl:rotate-180" />
        {t("back")}
      </Link>
      {isLoading ? <Skeleton className="h-64 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
      {data ? (
        <Card>
          <div className="mb-4 flex flex-col gap-1">
            <ExtraStatus status={data.status} />
            <h1 className="text-h3 font-semibold">{data.item ?? t("number", { number: data.number })}</h1>
            <p className="text-body-sm text-secondary">
              {t("number", { number: data.number })} · {labels.coverage(data.coverage)} · {t("submittedOn", { date: formatDay(data.created_at, market.locale) })}
            </p>
            {data.outcome ? <p className="text-body font-medium">{t("outcomeLabel", { outcome: labels.outcome(data.outcome) })}</p> : null}
          </div>
          <Thread
            messages={data.messages ?? []}
            onSend={async (body) => {
              client.setQueryData(key, (await api.postWarrantyMessage(market.slug, number, body)).data);
            }}
          />
        </Card>
      ) : null}
    </div>
  );
}
