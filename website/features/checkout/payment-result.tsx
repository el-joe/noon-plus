"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { AlertTriangle, Clock } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, Card, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import type { Order } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { errorMessage, useMounted, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { followPayment } from "./checkout-view";
import { ReferencePaymentResult } from "./reference-payment";

const PAID = new Set(["authorized", "captured"]);

/**
 * Methods offered to retry a failed payment: the checkout list without cash on
 * delivery, which the API refuses once the order is placed ("Cash on delivery
 * cannot be chosen after the order is placed.").
 */
export function retryMethods<T extends { code: string }>(methods: T[]): T[] {
  return methods.filter((m) => m.code !== "cod");
}

/**
 * `/checkout/payment?order=&payment=&status=` — where the gateway returns the
 * customer (PaymentController::toStorefront). The page never trusts the query
 * string: it asks the API to verify (`POST orders/{n}/payments/verify`) and
 * shows success, a wait state, or the failure + retry (Figma 68:797, 102:17396).
 * Gift cards, Nawy One and ad packages come back with `?payment=&kind=` and no
 * order: those are verified by reference (ReferencePaymentResult).
 */
export function PaymentResult() {
  const params = useSearchParams();
  const order = params.get("order");
  const reference = params.get("payment");
  if (!order && reference) return <ReferenceGate reference={reference} kind={params.get("kind")} />;
  return <OrderPaymentResult />;
}

/** Non-order payments need the payer's session too. */
function ReferenceGate({ reference, kind }: { reference: string; kind: string | null }) {
  const t = useTranslations();
  const href = useHref();
  const mounted = useMounted();
  const { user, isLoading } = useSession();
  if (!mounted || isLoading) return <Skeleton className="mx-auto h-72 max-w-xl" />;
  if (!user) {
    const back = `${href("/checkout/payment")}?payment=${encodeURIComponent(reference)}${kind ? `&kind=${encodeURIComponent(kind)}` : ""}`;
    return (
      <Card className="mx-auto max-w-xl text-center">
        <p className="mb-4 text-body">{t("checkout.signInHint")}</p>
        <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(back)}`}>{t("checkout.signIn")}</ButtonLink>
      </Card>
    );
  }
  return <ReferencePaymentResult reference={reference} kind={kind} />;
}

function OrderPaymentResult() {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const mounted = useMounted();
  const { user, isLoading } = useSession();
  const params = useSearchParams();
  const number = params.get("order") ?? "";
  const [order, setOrder] = useState<Order | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [method, setMethod] = useState<string | null>(null);
  const started = useRef(false);

  const verify = useMutation({
    mutationFn: async () => (await api.verifyPayment(market.slug, number)).data,
    onSuccess: setOrder,
    onError: (e) => setError(errorMessage(e, t("common.error"))),
  });

  useEffect(() => {
    if (mounted && user && number && !started.current) {
      started.current = true;
      verify.mutate();
    }
  }, [mounted, user, number, verify]);

  const status = order?.payment?.status ?? null;
  // QA-009: a capture for a different amount is held for an admin (`under_review`), never "paid".
  const underReview = status === "under_review" || order?.payment_status === "under_review";
  const paid = order && !underReview && (!order.payment || PAID.has(status ?? "") || order.payment?.next_action.type === "none") && status !== "failed" && status !== "expired";

  useEffect(() => {
    if (paid && order) router.replace(href(`/checkout/success?order=${encodeURIComponent(order.number)}`));
  }, [paid, order, router, href]);

  const failed = order && (status === "failed" || status === "expired" || order.payment?.next_action.type === "retry");
  const methods = useQuery({
    queryKey: ["payment-methods", market.slug, order?.totals.total?.amount],
    enabled: !!failed && !!order?.totals.total,
    queryFn: async () => retryMethods((await api.getPaymentMethods(market.slug, order!.totals.total!.amount)).data),
  });

  const retry = useMutation({
    mutationFn: async () => (await api.retryPayment(market.slug, number, method!)).data,
    onSuccess: (next) => {
      const target = followPayment(next, href);
      if (target.startsWith("http")) window.location.href = target;
      else {
        setOrder(next);
        router.replace(target);
      }
    },
    onError: (e) => setError(errorMessage(e, t("common.error"))),
  });

  if (!mounted || isLoading) return <Skeleton className="mx-auto h-72 max-w-xl" />;
  if (!user) {
    return (
      <Card className="mx-auto max-w-xl text-center">
        <p className="mb-4 text-body">{t("checkout.signInHint")}</p>
        <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(`${href("/checkout/payment")}?order=${encodeURIComponent(number)}`)}`}>{t("checkout.signIn")}</ButtonLink>
      </Card>
    );
  }
  if (error && !order) return <Alert tone="danger" className="mx-auto max-w-xl">{error}</Alert>;
  if (!order || paid) {
    return (
      <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
        <span className="size-8 animate-spin rounded-full border-4 border-brand border-t-transparent" />
        <p className="text-body">{t("payment.verifying")}</p>
      </Card>
    );
  }

  if (underReview) return <UnderReviewCard number={order.number} total={formatMoney(order.totals.total)} />;

  if (failed) {
    return (
      <Card className="mx-auto flex max-w-xl flex-col gap-4">
        <div className="flex items-start gap-3">
          <AlertTriangle className="size-6 shrink-0 text-danger-text" />
          <div>
            <h1 className="text-h3 font-semibold">{t("payment.failedTitle")}</h1>
            <p className="text-body text-secondary">{order.payment?.failure?.message ?? t("payment.failedHint")}</p>
            <p className="mt-1 text-body-sm text-secondary">{t("confirmation.order", { number: order.number })} · {formatMoney(order.totals.total)}</p>
          </div>
        </div>
        <div className="flex flex-col gap-2">
          {(methods.data ?? []).map((m) => (
            <label key={m.code} className={clsx("flex cursor-pointer items-center gap-3 rounded-md border p-3", method === m.code ? "border-brand bg-brand-subtle" : "border-line")}>
              <input type="radio" name="retry-method" checked={method === m.code} onChange={() => setMethod(m.code)} />
              <span className="text-body font-medium">{m.name}</span>
              {m.description ? <span className="text-body-sm text-secondary">{m.description}</span> : null}
            </label>
          ))}
        </div>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Button size="lg" disabled={!method || retry.isPending} onClick={() => retry.mutate()}>
          {retry.isPending ? t("payment.redirecting") : t("payment.retry")}
        </Button>
      </Card>
    );
  }

  return (
    <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
      <Clock className="size-8 text-brand-text" />
      <h1 className="text-h3 font-semibold">{t("payment.pendingTitle")}</h1>
      <p className="text-body text-secondary">{t("payment.pendingHint")}</p>
      {order.payment?.next_action.type === "redirect" && order.payment.next_action.url ? (
        <ButtonLink href={order.payment.next_action.url}>{t("account.order.payNow")}</ButtonLink>
      ) : null}
      <Button variant="outline" onClick={() => verify.mutate()} disabled={verify.isPending}>
        {t("payment.refresh")}
      </Button>
    </Card>
  );
}

/** Payment `under_review` (QA-009): the gateway reported another amount; Nawy checks it by hand. */
export function UnderReviewCard({ number, total }: { number: string; total: string }) {
  const t = useTranslations();
  const href = useHref();
  return (
    <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
      <Clock className="size-8 text-link" />
      <h1 className="text-h3 font-semibold">{t("payment.underReviewTitle")}</h1>
      <p className="text-body text-secondary">{t("payment.underReviewHint")}</p>
      <p className="text-body-sm text-secondary">
        {t("confirmation.order", { number })} · {total}
      </p>
      <ButtonLink href={href(`/account/orders/${encodeURIComponent(number)}`)} variant="outline">
        {t("payment.viewOrder")}
      </ButtonLink>
    </Card>
  );
}
