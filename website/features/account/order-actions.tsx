"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Modal } from "@/components/layout/modal";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Textarea } from "@/components/ui/field";
import { Alert, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import type { Order } from "@/lib/api/schemas";
import { queryKeys, useErrorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

type Shipment = Order["shipments"][number];
export type ShipmentActionKey = "track" | "invoice" | "cancel" | "return" | "review" | "buy_again" | "dispute";

/** Printable HTML tax invoice (G-A6), opened through the same-origin route in a new tab. */
export function invoiceHref(marketSlug: string, number: string, format: "html" | "pdf" = "html"): string {
  const base = `/api/invoice/${marketSlug}/${encodeURIComponent(number)}`;
  return format === "pdf" ? `${base}?format=pdf` : base;
}

/**
 * The buttons of one shipment, driven by the API's `actions` booleans
 * (QC-026, Figma 33:658): Track, Invoice, Cancel (reason codes, QA-041),
 * Return, Review, Buy again (PUT cart/items) and Dispute. `hide` drops
 * buttons the current page already shows (Track on the order page).
 */
export function ShipmentActions({ order, shipment, onUpdated, hide = [] }: { order: Order; shipment: Shipment; onUpdated?: (order: Order) => void; hide?: ShipmentActionKey[] }) {
  const t = useTranslations("accountPlus.orders");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const errorText = useErrorMessage();
  const [cancelling, setCancelling] = useState(false);
  const actions = shipment.actions ?? {};
  const show = (key: ShipmentActionKey) => !!actions[key] && !hide.includes(key);

  const buyAgain = useMutation({
    mutationFn: async () => {
      const lines = shipment.items.filter((i) => typeof i.listing_id === "number");
      for (const line of lines) await api.setCartItem(market.slug, line.listing_id!, Math.max(1, line.quantity));
    },
    onSuccess: () => {
      client.invalidateQueries({ queryKey: queryKeys.cart(market.slug) });
      router.push(href("/cart"));
    },
  });

  const orderPath = href(`/account/orders/${encodeURIComponent(order.number)}`);
  const n = encodeURIComponent(order.number);
  const s = encodeURIComponent(shipment.number);

  return (
    <div className="flex flex-col items-stretch gap-2 sm:items-end">
      <div className="flex flex-wrap gap-2 sm:justify-end">
        {show("track") ? (
          <ButtonLink size="sm" href={`${orderPath}#shipment-${s}`}>
            {t("track")}
          </ButtonLink>
        ) : null}
        {show("return") ? (
          <ButtonLink size="sm" href={href(`/account/returns/new?order=${n}&shipment=${s}`)}>
            {t("return")}
          </ButtonLink>
        ) : null}
        {show("invoice") && order.invoice_available !== false ? (
          <a href={invoiceHref(market.slug, order.number)} target="_blank" rel="noopener" className="inline-flex h-8 items-center justify-center rounded-md border border-line-strong bg-surface px-3 text-label font-semibold hover:bg-subtle">
            {t("invoice")}
          </a>
        ) : null}
        {show("cancel") ? (
          <Button size="sm" variant="outline" onClick={() => setCancelling(true)}>
            {t("cancel")}
          </Button>
        ) : null}
        {show("review") ? (
          <ButtonLink size="sm" variant="outline" href={href("/account/reviews")}>
            {t("review")}
          </ButtonLink>
        ) : null}
        {show("buy_again") && shipment.items.some((i) => typeof i.listing_id === "number") ? (
          <Button size="sm" variant="outline" onClick={() => buyAgain.mutate()} disabled={buyAgain.isPending}>
            {buyAgain.isPending ? t("adding") : t("buyAgain")}
          </Button>
        ) : null}
        {show("dispute") ? (
          <ButtonLink size="sm" variant="ghost" href={href(`/account/disputes/new?shipment=${s}`)}>
            {t("dispute")}
          </ButtonLink>
        ) : null}
      </div>
      {buyAgain.error ? <p className="text-body-sm text-danger-text">{errorText(buyAgain.error)}</p> : null}
      {cancelling ? (
        <CancelShipmentDialog
          order={order}
          shipment={shipment}
          onClose={() => setCancelling(false)}
          onDone={(next) => {
            setCancelling(false);
            client.invalidateQueries({ queryKey: ["orders", market.slug] });
            onUpdated?.(next);
          }}
        />
      ) : null}
    </div>
  );
}

/** Cancel one shipment with a reason code from `orders/cancel-reasons`; `other` needs a note (QA-041). */
export function CancelShipmentDialog({ order, shipment, onClose, onDone }: { order: Order; shipment: Shipment; onClose: () => void; onDone: (order: Order) => void }) {
  const t = useTranslations("accountPlus.cancel");
  const market = useMarket();
  const errorText = useErrorMessage();
  const reasons = useQuery({ queryKey: ["cancel-reasons", market.slug], staleTime: 60 * 60_000, queryFn: async () => (await api.getCancelReasons(market.slug)).data });
  const [reason, setReason] = useState("");
  const [note, setNote] = useState("");
  const [noteError, setNoteError] = useState<string | null>(null);
  const needsNote = !!reasons.data?.find((r) => r.code === reason)?.needs_note;
  const cancel = useMutation({
    mutationFn: async () => (await api.cancelShipment(market.slug, order.number, shipment.number, reason, needsNote ? note.trim() : undefined)).data,
    onSuccess: onDone,
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!reason) return;
    if (needsNote && !note.trim()) {
      setNoteError(t("noteRequired"));
      return;
    }
    setNoteError(null);
    cancel.mutate();
  };

  return (
    <Modal title={t("title")} onClose={onClose}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <p className="text-body-sm text-secondary">{t("intro", { shipment: shipment.number })}</p>
        {reasons.isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {reasons.error ? <Alert tone="danger">{errorText(reasons.error)}</Alert> : null}
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-label font-semibold">{t("reason")}</legend>
          {reasons.data?.map((r) => (
            <label key={r.code} className="flex cursor-pointer items-center gap-3 rounded-md border border-line px-3 py-2 text-body has-[:checked]:border-brand has-[:checked]:bg-brand-subtle">
              <input type="radio" name="reason" value={r.code} checked={reason === r.code} onChange={() => setReason(r.code)} className="accent-[var(--color-brand)]" />
              {r.label}
            </label>
          ))}
        </fieldset>
        {needsNote ? (
          <Field label={t("note")} error={noteError ?? fieldError(cancel.error, "note")}>
            <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} rows={3} required />
          </Field>
        ) : null}
        <p className="text-body-sm text-secondary">{t("hint")}</p>
        {cancel.error ? <Alert tone="danger">{errorText(cancel.error)}</Alert> : null}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t("keep")}
          </Button>
          <Button type="submit" disabled={!reason || cancel.isPending}>
            {cancel.isPending ? t("cancelling") : t("confirm")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function fieldError(error: unknown, field: string): string | null {
  const fields = (error as { fields?: Record<string, string[]> })?.fields;
  return fields?.[field]?.[0] ?? null;
}
