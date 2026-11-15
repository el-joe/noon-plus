"use client";

import { useMutation } from "@tanstack/react-query";
import clsx from "clsx";
import { Send } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/field";
import { Alert, Badge } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status-badge";
import { ApiError } from "@/lib/api/client";
import type { ThreadMessage } from "@/lib/api/account-extra";
import { formatDateTime } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

const TONES: Record<string, "success" | "warning" | "danger" | "info" | "neutral"> = {
  open: "warning",
  seller_responded: "info",
  under_review: "info",
  escalated: "warning",
  resolved: "success",
  closed: "neutral",
  submitted: "warning",
  approved: "success",
  rejected: "danger",
  in_repair: "info",
  replacement_sent: "info",
  refund_issued: "success",
  requested: "warning",
  awaiting_pickup: "info",
  in_transit: "info",
  received: "info",
  inspecting: "info",
  completed: "success",
  cancelled: "danger",
  active: "success",
  used: "neutral",
  redeemed: "neutral",
  expired: "danger",
  pending_payment: "warning",
  scheduled: "info",
  incomplete: "warning",
  trialing: "info",
  past_due: "danger",
};

/** Status chip for disputes, warranty claims, returns, gift cards and memberships (EN/AR labels). */
export function ExtraStatus({ status }: { status: string }) {
  const t = useTranslations("accountExtra.status");
  const key = status as "open";
  if (!t.has(key)) return <StatusBadge status={status} />;
  return <Badge tone={TONES[status] ?? "neutral"}>{t(key)}</Badge>;
}

/** 422 `error.fields` → first message per field (G-W11 envelope). */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, messages]) => [field, messages[0] ?? ""]));
}

/** General error unless the 422 is fully covered by the given form fields. */
export function formError(error: unknown, fields: string[], fallback: string): string | null {
  if (!error) return null;
  const errors = fieldErrors(error);
  const keys = Object.keys(errors);
  if (keys.length > 0 && keys.every((k) => fields.includes(k))) return null;
  return errorMessage(error, fallback);
}

/** Message thread with a reply box (dispute and warranty claim detail, Figma 49:1706). */
export function Thread({ messages, onSend }: { messages: ThreadMessage[]; onSend: (body: string) => Promise<unknown> }) {
  const t = useTranslations("accountExtra");
  const tc = useTranslations("common");
  const market = useMarket();
  const [body, setBody] = useState("");
  const send = useMutation({
    mutationFn: (text: string) => onSend(text),
    onSuccess: () => setBody(""),
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (body.trim()) send.mutate(body.trim());
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 rounded-md bg-subtle p-3">
        {messages.length === 0 ? <p className="text-body-sm text-secondary">{t("thread.empty")}</p> : null}
        {messages.map((message, i) => {
          const mine = message.from === "customer";
          const from = message.from as "customer";
          return (
            <div key={i} className={clsx("max-w-[85%] rounded-md p-3", mine ? "self-end bg-brand-subtle" : "self-start bg-surface")}>
              <p className="mb-1 text-caption text-secondary">
                {t.has(`from.${from}`) ? t(`from.${from}`) : message.from} · {formatDateTime(message.at, market.locale, market.country.timeZone)}
              </p>
              <p className="whitespace-pre-line text-body">{message.body}</p>
            </div>
          );
        })}
      </div>
      <form onSubmit={submit} className="flex flex-col gap-2">
        <label className="text-label font-medium" htmlFor="thread-reply">
          {t("thread.reply")}
        </label>
        <div className="flex items-end gap-2">
          <Textarea id="thread-reply" value={body} onChange={(e) => setBody(e.target.value)} maxLength={2000} placeholder={t("thread.placeholder")} className="min-h-11 flex-1" />
          <Button type="submit" disabled={!body.trim() || send.isPending}>
            <Send className="size-4 rtl:-scale-x-100" />
            {t("thread.send")}
          </Button>
        </div>
        {send.error ? <Alert tone="danger">{errorMessage(send.error, tc("error"))}</Alert> : null}
      </form>
    </div>
  );
}
