"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { RotateCcw } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status-badge";
import * as api from "@/lib/api/endpoints";
import type { ReturnRequest } from "@/lib/api/schemas";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

const REASONS = ["defective", "damaged_on_arrival", "wrong_item", "not_as_described", "changed_mind", "size_or_fit", "other"] as const;
const METHODS = ["pickup", "drop_off"] as const;
const REFUND_TO = ["original", "wallet"] as const;

/** `/account/returns` (Figma 49:1155). */
export function ReturnsList() {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const key = ["returns", market.slug];
  const list = useQuery({ queryKey: [...key, page], queryFn: async () => api.getReturns(market.slug, page) });
  const { isLoading, error } = list;
  const data = list.data?.data;
  // G-A4: paging from meta.last_page.
  const pages = Math.max(1, Number(list.data?.meta.last_page ?? 1));
  const cancel = useMutation({
    mutationFn: async (number: string) => api.cancelReturn(market.slug, number),
    onSuccess: () => client.invalidateQueries({ queryKey: key }),
  });

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.returns.title")}</h1>
      {isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {error ? <Alert tone="danger">{errorMessage(error, t("common.error"))}</Alert> : null}
      {cancel.error ? <Alert tone="danger">{errorMessage(cancel.error, t("common.error"))}</Alert> : null}
      {data && data.length === 0 ? <EmptyState icon={<RotateCcw className="size-10" />} title={t("account.returns.empty")} /> : null}
      {data?.map((r) => <ReturnCard key={r.number} item={r} onCancel={() => cancel.mutate(r.number)} cancelling={cancel.isPending} />)}
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

function ReturnCard({ item, onCancel, cancelling }: { item: ReturnRequest; onCancel: () => void; cancelling: boolean }) {
  const t = useTranslations();
  const market = useMarket();
  const reason = item.reason as (typeof REASONS)[number];
  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="flex flex-wrap items-center gap-2 text-body font-semibold">
            {item.number} <StatusBadge status={item.status} />
          </p>
          <p className="text-body-sm text-secondary">
            {t("account.returns.requested", { date: formatDay(item.created_at, market.locale) })}
            {item.shipment ? ` · ${item.shipment}` : ""} · {REASONS.includes(reason) ? t(`account.returns.reason.${reason}`) : item.reason}
          </p>
        </div>
        {item.refund ? (
          <p className="text-body font-semibold text-success-text">{t("account.returns.refund", { amount: formatMoney(item.refund.amount) })}</p>
        ) : null}
      </div>
      <ul className="mt-3 flex flex-col gap-1 text-body-sm">
        {item.items.map((line) => (
          <li key={line.order_item_id}>
            {line.title} × {line.quantity}
          </li>
        ))}
      </ul>
      {item.rejection_reason ? <Alert tone="danger" className="mt-3">{item.rejection_reason}</Alert> : null}
      {item.status === "requested" ? (
        <Button variant="outline" size="sm" className="mt-3" onClick={onCancel} disabled={cancelling}>
          {t("account.returns.cancel")}
        </Button>
      ) : null}
    </Card>
  );
}

/** `/account/returns/new?order=&shipment=` — return request (Figma return wizard 104:5646 …, single page). */
export function NewReturnForm() {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const params = useSearchParams();
  const number = params.get("order") ?? "";
  const shipmentNumber = params.get("shipment") ?? "";
  const order = useQuery({ queryKey: ["order", market.slug, number], enabled: !!number, queryFn: async () => (await api.getOrder(market.slug, number)).data });
  const shipment = order.data?.shipments.find((s) => s.number === shipmentNumber);
  const [quantities, setQuantities] = useState<Record<number, number>>({});
  const [error, setError] = useState<string | null>(null);

  const submit = useMutation({
    mutationFn: async (form: FormData) => (await api.createReturn(market.slug, form)).data,
    onSuccess: () => router.push(href("/account/returns")),
    onError: (e) => setError(errorMessage(e, t("common.error"))),
  });

  if (!number || !shipmentNumber) return <EmptyState title={t("account.returns.new")} action={<ButtonLink href={href("/account/orders")}>{t("account.orders.title")}</ButtonLink>} />;
  if (order.isLoading) return <Skeleton className="h-96 w-full" />;
  if (!shipment) return <Alert tone="danger">{errorMessage(order.error, t("common.notFoundTitle"))}</Alert>;

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const source = new FormData(event.currentTarget);
    const form = new FormData();
    form.set("shipment", shipment.number);
    const chosen = Object.entries(quantities).filter(([, q]) => q > 0);
    if (chosen.length === 0) return setError(t("account.returns.chooseItems"));
    chosen.forEach(([id, q], i) => {
      form.set(`items[${i}][order_item_id]`, id);
      form.set(`items[${i}][quantity]`, String(q));
    });
    for (const key of ["reason", "method", "refund_destination", "comment"]) {
      const v = source.get(key);
      if (typeof v === "string" && v) form.set(key, v);
    }
    for (const photo of source.getAll("photos").slice(0, 5)) if (photo instanceof File && photo.size > 0) form.append("photos[]", photo);
    submit.mutate(form);
  };

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("account.returns.new")}</h1>
      <p className="text-body text-secondary">{t("account.returns.forShipment", { shipment: shipment.number })}</p>
      <Card>
        <h2 className="mb-3 text-h4 font-semibold">{t("account.returns.chooseItems")}</h2>
        <ul className="flex flex-col gap-3">
          {shipment.items.map((item) => (
            <li key={item.id} className="flex items-center justify-between gap-3">
              <span className="text-body">{item.title}</span>
              <select
                aria-label={t("product.quantity")}
                value={quantities[item.id] ?? 0}
                onChange={(e) => setQuantities({ ...quantities, [item.id]: Number(e.target.value) })}
                className="h-9 rounded-md border border-line-strong bg-surface px-2"
              >
                {Array.from({ length: item.quantity + 1 }, (_, q) => (
                  <option key={q} value={q}>
                    {q}
                  </option>
                ))}
              </select>
            </li>
          ))}
        </ul>
      </Card>
      <Card className="grid gap-4 md:grid-cols-3">
        <Field label={t("account.returns.reasonLabel")}>
          <Select name="reason" required defaultValue="defective">
            {REASONS.map((r) => (
              <option key={r} value={r}>
                {t(`account.returns.reason.${r}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={t("account.returns.methodLabel")}>
          <Select name="method" required defaultValue="pickup">
            {METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`account.returns.method.${m}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={t("account.returns.refundLabel")}>
          <Select name="refund_destination" required defaultValue="original">
            {REFUND_TO.map((d) => (
              <option key={d} value={d}>
                {t(`account.returns.refundTo.${d}`)}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={`${t("account.returns.comment")} (${t("common.optional")})`} className="md:col-span-3">
          <Textarea name="comment" maxLength={1000} />
        </Field>
        <Field label={t("account.returns.photos")} className="md:col-span-3">
          <input type="file" name="photos" accept="image/*" multiple className="text-body-sm" />
        </Field>
      </Card>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Button type="submit" size="lg" disabled={submit.isPending} className="self-start">
        {t("account.returns.submit")}
      </Button>
    </form>
  );
}
