"use client";

import { useMutation } from "@tanstack/react-query";
import { AlertTriangle, CheckCircle2, Clock } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useRef } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, Card } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { PAYMENT_PURPOSES, type PaymentPurpose, type PaymentStatus } from "@/lib/api/schemas";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

const PAID = new Set(["authorized", "captured"]);
const FAILED = new Set(["failed", "expired", "cancelled"]);

/**
 * Where each purpose goes after (or to retry) the payment. D14 trip promotions
 * are bought and followed in the Travel Partner panel (the backend sends that
 * return there), so the storefront shows the promotion's state, no link.
 */
const NEXT: Record<Exclude<PaymentPurpose, "order">, { done: string; retry: string } | null> = {
  gift_card: { done: "/account/gift-cards", retry: "/gift-cards" },
  membership_invoice: { done: "/account/membership", retry: "/membership" },
  classified_package: { done: "/account/my-ads", retry: "/account/my-ads" },
  travel_promotion: null,
};

type Subject = PaymentStatus["subject"];

/**
 * D14: what a trip-promotion payment means for the promotion, read from the
 * API subject (`status`, `starts_at`, `ends_at`) — never from the query string.
 * Same wording as the Travel Partner panel return.
 */
export function travelPromotionState(paymentStatus: string, subject: Subject, now: Date = new Date()): "active" | "queued" | "pending" | "notLive" | "failed" {
  if (subject?.status === "active") return subject.starts_at && new Date(subject.starts_at).getTime() > now.getTime() ? "queued" : "active";
  if (PAID.has(paymentStatus) || paymentStatus === "partially_refunded" || paymentStatus === "refunded") return subject?.status === "pending_payment" ? "pending" : "notLive";
  if (FAILED.has(paymentStatus)) return "failed";
  return "pending";
}

const purposeOf = (value: string | null | undefined): PaymentPurpose | null => ((PAYMENT_PURPOSES as readonly string[]).includes(value ?? "") ? (value as PaymentPurpose) : null);

/**
 * Gateway return for non-order payments (G-W31):
 * `/checkout/payment?payment={reference}&kind={gift_card|membership_invoice|classified_package|travel_promotion}&status=`.
 * The query string is never trusted: `POST payments/{reference}/verify` (payer
 * only) gives the payment and its subject; an order payment is handed to the
 * order flow (`?order=`).
 */
export function ReferencePaymentResult({ reference, kind }: { reference: string; kind: string | null }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const started = useRef(false);
  const verify = useMutation({
    mutationFn: async () => {
      try {
        return (await api.verifyPaymentByReference(market.slug, reference)).data;
      } catch (error) {
        // A payment that cannot be verified any more (already settled) is still readable.
        if (error instanceof ApiError && (error.status === 409 || error.status === 422)) return (await api.getPaymentByReference(market.slug, reference)).data;
        throw error;
      }
    },
  });

  useEffect(() => {
    if (!started.current) {
      started.current = true;
      verify.mutate();
    }
  }, [verify]);

  const data: PaymentStatus | undefined = verify.data;
  const purpose = purposeOf(data?.purpose) ?? purposeOf(kind);

  useEffect(() => {
    if (data && purposeOf(data.purpose) === "order" && data.subject?.number) {
      router.replace(`${href("/checkout/payment")}?order=${encodeURIComponent(data.subject.number)}`);
    }
  }, [data, router, href]);

  if (verify.error) {
    const missing = verify.error instanceof ApiError && (verify.error.status === 404 || verify.error.status === 403);
    return <Alert tone="danger" className="mx-auto max-w-xl">{missing ? t("payment.notFound") : errorMessage(verify.error, t("common.error"))}</Alert>;
  }
  if (!data || purpose === "order") {
    return (
      <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
        <span className="size-8 animate-spin rounded-full border-4 border-brand border-t-transparent" />
        <p className="text-body">{t("payment.verifying")}</p>
      </Card>
    );
  }

  const payment = data.payment;
  const subject = data.subject;
  const promotionState = purpose === "travel_promotion" ? travelPromotionState(payment.status, subject) : null;
  const promotionBody = promotionState
    ? t(`payment.travelPromotion.${promotionState}`, { start: formatDay(subject?.starts_at, market.locale), date: formatDay(subject?.ends_at, market.locale) })
    : null;
  const next = purpose ? NEXT[purpose] : { done: "/account", retry: "/account" };
  const panelHint = purpose === "travel_promotion" ? <p className="text-body-sm text-secondary">{t("payment.travelPartnerHint")}</p> : null;
  const label = purpose ? t(`payment.kind.${purpose}`) : null;
  const amount = formatMoney(subject?.amount ?? payment.amount);
  const meta = (
    <p className="text-body-sm text-secondary">
      {label ? `${label} · ` : ""}
      {t("payment.reference", { reference: subject?.number ?? payment.reference.slice(0, 8), amount })}
    </p>
  );

  if (payment.status === "under_review") {
    return (
      <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
        <Clock className="size-8 text-link" />
        <h1 className="text-h3 font-semibold">{t("payment.underReviewTitle")}</h1>
        <p className="text-body text-secondary">{promotionBody ?? t("payment.underReviewHint")}</p>
        {meta}
        {panelHint}
      </Card>
    );
  }

  if (PAID.has(payment.status)) {
    const body =
      purpose === "gift_card"
        ? t("payment.paidBody.gift_card", { number: subject?.masked_code ?? subject?.number ?? "", email: subject?.recipient_email ?? "" })
        : purpose === "membership_invoice"
          ? t("payment.paidBody.membership_invoice")
          : purpose === "classified_package"
            ? t("payment.paidBody.classified_package", { date: formatDay(subject?.applied_until, market.locale) })
            : null;
    return (
      <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
        <CheckCircle2 className="size-12 text-success-text" />
        <h1 className="text-h3 font-semibold">{t("payment.paidTitle")}</h1>
        {(promotionBody ?? body) ? <p className="text-body text-secondary">{promotionBody ?? body}</p> : null}
        {meta}
        {purpose && purpose !== "travel_promotion" && next ? <ButtonLink href={href(next.done)}>{t(`payment.next.${purpose}`)}</ButtonLink> : null}
      </Card>
    );
  }

  if (FAILED.has(payment.status) || payment.next_action.type === "retry") {
    return (
      <Card className="mx-auto flex max-w-xl flex-col gap-4">
        <div className="flex items-start gap-3">
          <AlertTriangle className="size-6 shrink-0 text-danger-text" />
          <div>
            <h1 className="text-h3 font-semibold">{t("payment.failedTitle")}</h1>
            <p className="text-body text-secondary">{purpose === "travel_promotion" ? t("payment.travelPromotion.failed") : (payment.failure?.message ?? t("payment.failedBodyOther"))}</p>
            {meta}
            {panelHint}
          </div>
        </div>
        {next ? (
          <ButtonLink href={href(next.retry)} size="lg">
            {t("payment.tryAgain")}
          </ButtonLink>
        ) : null}
      </Card>
    );
  }

  return (
    <Card className="mx-auto flex max-w-xl flex-col items-center gap-3 py-10 text-center">
      <Clock className="size-8 text-brand-text" />
      <h1 className="text-h3 font-semibold">{t("payment.pendingTitle")}</h1>
      <p className="text-body text-secondary">{promotionBody ?? t("payment.pendingHint")}</p>
      {meta}
      {panelHint}
      {payment.next_action.type === "redirect" && payment.next_action.url && /^https?:\/\//.test(payment.next_action.url) ? (
        <ButtonLink href={payment.next_action.url}>{t("account.order.payNow")}</ButtonLink>
      ) : null}
      <Button variant="outline" onClick={() => verify.mutate()} disabled={verify.isPending}>
        {t("payment.refresh")}
      </Button>
    </Card>
  );
}
