"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { Gift } from "lucide-react";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { useCallback, useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import { ExtraStatus, fieldErrors, formError } from "@/features/account-extra/shared";
import * as api from "@/lib/api/shop";
import { formatAmount, formatDateTime, formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { followNextAction, PaymentMethodPicker } from "./payment";

/** Card face tones; designs come from `GET gift-cards/designs`. */
const TONES = ["bg-brand", "bg-info-subtle", "bg-success-subtle", "bg-warning-subtle", "bg-brand-subtle", "bg-subtle"] as const;
const FALLBACK_DESIGNS: api.GiftCardDesign[] = [{ key: "classic", name: "Classic" }];

function presets(decimals: number): string[] {
  return decimals === 3 ? ["10", "20", "50", "100"] : ["50", "100", "200", "500"];
}

/** `/gift-cards` (Figma Gift cards 71:11244): buy a card for someone, redeem one. */
export function GiftCardsPage() {
  const t = useTranslations("shop.giftCards");
  const market = useMarket();
  const [preview, setPreview] = useState<string>(presets(market.country.decimals)[2]);

  return (
    <div className="flex flex-col gap-5">
      <section className="flex flex-wrap items-center justify-between gap-6 rounded-xl bg-ink p-6 text-white md:p-10">
        <div className="max-w-xl">
          <h1 className="text-h1 font-bold">{t("title")}</h1>
          <p className="mt-2 text-body opacity-80">{t("subtitle")}</p>
        </div>
        <GiftCardArt amount={`${market.country.currency} ${formatAmount(preview || "0")}`} />
      </section>
      <div className="grid items-start gap-5 lg:grid-cols-[1fr_360px]">
        <BuyGiftCard onAmount={setPreview} />
        <RedeemGiftCard />
      </div>
    </div>
  );
}

function GiftCardArt({ amount, className }: { amount: string; className?: string }) {
  return (
    <div className={clsx("flex aspect-[1.6] w-64 flex-col justify-between rounded-lg bg-brand p-5 text-ink shadow-md", className)} aria-hidden>
      <span className="text-h3 font-bold">nawy</span>
      <span className="text-h2 font-bold" dir="ltr">
        {amount}
      </span>
    </div>
  );
}

function BuyGiftCard({ onAmount }: { onAmount: (amount: string) => void }) {
  const t = useTranslations("shop.giftCards");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const pathname = usePathname();
  const client = useQueryClient();
  const { user, isLoading: sessionLoading } = useSession();
  const amounts = presets(market.country.decimals);
  const designs = useQuery({
    queryKey: ["gift-card-designs", market.slug],
    staleTime: 60 * 60_000,
    queryFn: async () => (await api.getGiftCardDesigns(market.slug)).data,
  });
  const designList = designs.data?.length ? designs.data : FALLBACK_DESIGNS;
  const [design, setDesign] = useState("classic");
  const loose = t as unknown as ((key: string) => string) & { has: (key: string) => boolean };
  const designLabel = (d: api.GiftCardDesign) => (loose.has(`designs.${d.key}`) ? loose(`designs.${d.key}`) : d.name);
  const [preset, setPreset] = useState<string | "custom">(amounts[2]);
  const [custom, setCustom] = useState("");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [later, setLater] = useState(false);
  const [sendAt, setSendAt] = useState("");
  const [method, setMethod] = useState<string | null>(null);
  const amount = preset === "custom" ? custom.trim() : preset;
  const validAmount = new RegExp(`^\\d{1,9}(\\.\\d{1,${market.country.decimals}})?$`).test(amount) && Number(amount) > 0;
  const onMethod = useCallback((code: string | null) => setMethod(code), []);

  const buy = useMutation({
    mutationFn: async () =>
      (
        await api.buyGiftCard(market.slug, {
          amount,
          payment_method: method!,
          recipient_email: email.trim(),
          recipient_name: name.trim() || null,
          message: message.trim() || null,
          send_at: later && sendAt ? new Date(sendAt).toISOString() : null,
          design,
        })
      ).data,
    onSuccess: (card) => {
      client.invalidateQueries({ queryKey: ["gift-cards", market.slug] });
      followNextAction(card.payment.next_action);
    },
  });
  const errors = fieldErrors(buy.error);
  const general = formError(buy.error, ["amount", "payment_method", "recipient_email", "recipient_name", "message", "send_at", "design"], tc("error"));
  const done = buy.data && buy.data.payment.next_action.type !== "redirect" ? buy.data : null;

  const pick = (value: string) => {
    setPreset(value);
    if (value !== "custom") onAmount(value);
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (validAmount && method) buy.mutate();
  };

  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-5">
        <div>
          <h2 className="mb-3 text-h4 font-semibold">{t("design")}</h2>
          {designs.isLoading ? <Skeleton className="h-20 w-full" /> : null}
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {designList.map((d, i) => (
              <button
                key={d.key}
                type="button"
                onClick={() => setDesign(d.key)}
                aria-pressed={design === d.key}
                className={clsx("flex aspect-[1.6] items-end justify-center rounded-md p-2 text-caption font-medium", TONES[i % TONES.length], design === d.key ? "ring-2 ring-ink" : "border border-line")}
              >
                {designLabel(d)}
              </button>
            ))}
          </div>
          {errors.design ? <p className="mt-2 text-body-sm text-danger-text">{errors.design}</p> : null}
          <p className="mt-2 text-body-sm text-secondary">{t("designHint")}</p>
        </div>
        <div>
          <h2 className="mb-3 text-h4 font-semibold">{t("amount")}</h2>
          <div className="flex flex-wrap gap-2">
            {amounts.map((a) => (
              <button
                key={a}
                type="button"
                onClick={() => pick(a)}
                aria-pressed={preset === a}
                className={clsx("rounded-full border px-3 py-1.5 text-body-sm font-medium", preset === a ? "border-ink bg-ink text-white" : "border-line-strong bg-surface")}
              >
                {market.country.currency} {a}
              </button>
            ))}
            <button
              type="button"
              onClick={() => pick("custom")}
              aria-pressed={preset === "custom"}
              className={clsx("rounded-full border px-3 py-1.5 text-body-sm font-medium", preset === "custom" ? "border-ink bg-ink text-white" : "border-line-strong bg-surface")}
            >
              {t("custom")}
            </button>
          </div>
          {preset === "custom" ? (
            <Field label={t("customAmount", { currency: market.country.currency })} error={errors.amount} className="mt-3 max-w-xs">
              <Input
                inputMode="decimal"
                value={custom}
                onChange={(e) => {
                  setCustom(e.target.value);
                  if (Number(e.target.value) > 0) onAmount(e.target.value);
                }}
                dir="ltr"
              />
            </Field>
          ) : errors.amount ? (
            <p className="mt-2 text-body-sm text-danger-text">{errors.amount}</p>
          ) : null}
        </div>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t("recipientName")} error={errors.recipient_name}>
            <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoComplete="off" />
          </Field>
          <Field label={t("recipientEmail")} error={errors.recipient_email}>
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} required dir="ltr" autoComplete="off" />
          </Field>
          <Field label={t("message")} error={errors.message}>
            <Textarea value={message} onChange={(e) => setMessage(e.target.value)} maxLength={500} className="min-h-11" />
          </Field>
          <div className="flex flex-col gap-3">
            <Field label={t("sendOn")}>
              <Select value={later ? "later" : "now"} onChange={(e) => setLater(e.target.value === "later")}>
                <option value="now">{t("sendNow")}</option>
                <option value="later">{t("sendLater")}</option>
              </Select>
            </Field>
            {later ? (
              <Field label={t("sendAt")} error={errors.send_at}>
                <Input type="datetime-local" value={sendAt} onChange={(e) => setSendAt(e.target.value)} required dir="ltr" />
              </Field>
            ) : null}
          </div>
        </div>
        {sessionLoading ? null : user ? (
          <>
            <PaymentMethodPicker amount={validAmount ? amount : null} value={method} onChange={onMethod} label={t("paymentMethod")} />
            {errors.payment_method ? <p className="text-body-sm text-danger-text">{errors.payment_method}</p> : null}
            {general ? <Alert tone="danger">{general}</Alert> : null}
            {done ? (
              <Alert tone="success">
                {done.send_at
                  ? t("scheduled", { number: done.number, date: formatDateTime(done.send_at, market.locale, market.country.timeZone) })
                  : t("bought", { number: done.number, email: done.recipient_email ?? "" })}
              </Alert>
            ) : null}
            <Button type="submit" size="lg" className="self-start" disabled={!validAmount || !method || !email.trim() || buy.isPending}>
              {t("buy", { amount: `${market.country.currency} ${validAmount ? formatAmount(amount) : "—"}` })}
            </Button>
          </>
        ) : (
          <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(pathname)}`} size="lg" className="self-start">
            {t("signInToBuy")}
          </ButtonLink>
        )}
      </form>
    </Card>
  );
}

/** Redeem box (gift cards page side card and account gift cards). */
export function RedeemGiftCard() {
  const t = useTranslations("shop.giftCards");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const pathname = usePathname();
  const client = useQueryClient();
  const { user, isLoading } = useSession();
  const [code, setCode] = useState("");
  const redeem = useMutation({
    mutationFn: async () => (await api.redeemGiftCard(market.slug, code.trim())).data,
    onSuccess: () => {
      setCode("");
      client.invalidateQueries({ queryKey: ["wallet", market.slug] });
    },
  });
  const errors = fieldErrors(redeem.error);

  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("haveCard")}</h2>
      {isLoading ? <Skeleton className="h-24 w-full" /> : null}
      {!isLoading && !user ? (
        <ButtonLink variant="outline" className="w-full" href={`${href("/login")}?next=${encodeURIComponent(pathname)}`}>
          {t("signInToRedeem")}
        </ButtonLink>
      ) : null}
      {user ? (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (code.trim()) redeem.mutate();
          }}
          className="flex flex-col gap-3"
        >
          <Field label={t("code")} error={errors.code}>
            <Input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={32} placeholder="NAWY-XXXX-XXXX-XXXX" dir="ltr" autoComplete="off" />
          </Field>
          <Button type="submit" variant="outline" disabled={!code.trim() || redeem.isPending}>
            {t("redeem")}
          </Button>
          {redeem.data ? <Alert tone="success">{t("redeemed", { amount: formatMoney(redeem.data.redeemed) })}</Alert> : null}
          {redeem.error && !errors.code ? <Alert tone="danger">{errorMessage(redeem.error, tc("error"))}</Alert> : null}
        </form>
      ) : null}
      <p className="mt-3 text-body-sm text-secondary">{t("redeemHint", { currency: market.country.currency, country: market.country.name[market.locale] })}</p>
    </Card>
  );
}

/** `/account/gift-cards` (Figma Account gift cards 51:4188): cards bought + redeem. */
export function AccountGiftCards() {
  const t = useTranslations("shop.giftCards");
  const tn = useTranslations("account.nav");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const { data, isLoading, error } = useQuery({ queryKey: ["gift-cards", market.slug], queryFn: async () => (await api.getGiftCards(market.slug)).data });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{tn("giftCards")}</h1>
        <ButtonLink href={href("/gift-cards")}>{t("buyOne")}</ButtonLink>
      </div>
      <div className="grid items-start gap-4 lg:grid-cols-[1fr_320px]">
        <div className="flex flex-col gap-3">
          <h2 className="text-h4 font-semibold">{t("myCards")}</h2>
          {isLoading ? <Skeleton className="h-40 w-full" /> : null}
          {error ? <Alert tone="danger">{errorMessage(error, tc("error"))}</Alert> : null}
          {data && data.length === 0 ? <EmptyState icon={<Gift className="size-10" />} title={t("noCards")} /> : null}
          {data?.map((card) => (
            <Card key={card.number} className="flex flex-wrap items-center gap-4">
              <GiftCardArt amount={formatMoney(card.amount)} className="w-36 p-3 [&>span:last-child]:text-h4" />
              <div className="flex min-w-0 flex-1 flex-col gap-1">
                <ExtraStatus status={card.status} />
                <p className="text-body font-semibold" dir="ltr">
                  {card.masked_code}
                </p>
                <p className="text-body-sm text-secondary">
                  {t("to", { name: card.recipient_name || card.recipient_email || "—" })}
                  {card.delivered_at ? ` · ${t("delivered", { date: formatDay(card.delivered_at, market.locale) })}` : card.send_at ? ` · ${t("sendsOn", { date: formatDay(card.send_at, market.locale) })}` : ""}
                </p>
                <p className="text-body-sm text-secondary">{t("expires", { date: formatDay(card.expires_at, market.locale) })}</p>
              </div>
            </Card>
          ))}
        </div>
        <RedeemGiftCard />
      </div>
    </div>
  );
}

/** `/account/vouchers` (Figma Account vouchers 51:4690): redeem a voucher into promotional credit. */
export function VouchersView() {
  const t = useTranslations("shop.vouchers");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [code, setCode] = useState("");
  const redeem = useMutation({
    mutationFn: async () => (await api.redeemVoucher(market.slug, code.trim())).data,
    onSuccess: () => {
      setCode("");
      client.invalidateQueries({ queryKey: ["wallet", market.slug] });
    },
  });
  const errors = fieldErrors(redeem.error);

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      <Card>
        <p className="mb-4 text-body text-secondary">{t("hint")}</p>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (code.trim()) redeem.mutate();
          }}
          className="flex flex-wrap items-end gap-3"
        >
          <Field label={t("code")} error={errors.code} className="min-w-60 flex-1">
            <Input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={32} dir="ltr" autoComplete="off" />
          </Field>
          <Button type="submit" disabled={!code.trim() || redeem.isPending}>
            {t("redeem")}
          </Button>
        </form>
        {redeem.data ? (
          <Alert tone="success" className="mt-3">
            {t("redeemed", { amount: formatMoney(redeem.data.promotional), date: formatDay(redeem.data.expires_at, market.locale) })}{" "}
            <a href={href("/account/credits")} className="font-semibold underline">
              {t("seeCredits")}
            </a>
          </Alert>
        ) : null}
        {redeem.error && !errors.code ? (
          <Alert tone="danger" className="mt-3">
            {errorMessage(redeem.error, tc("error"))}
          </Alert>
        ) : null}
        <p className="mt-4 text-body-sm text-secondary">{t("coupons")}</p>
      </Card>
    </div>
  );
}
