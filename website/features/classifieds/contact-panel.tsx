"use client";

import { useMutation } from "@tanstack/react-query";
import { BadgeCheck, CalendarCheck, CheckCircle2, Flag, MessageSquare, Phone, Store } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent, type ReactNode } from "react";
import { Modal } from "@/components/layout/modal";
import { Button, ButtonLink, buttonClass } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Badge } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdDetail } from "@/lib/api/classifieds";
import type { RequiredContract } from "@/lib/api/schemas";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { FavouriteButton } from "./ad-card";
import { ContractModal, fieldError, requiredContracts } from "./contract-modal";

type Summary = Pick<AdDetail, "number" | "title" | "price" | "status" | "seller" | "property"> & { request_types?: string[] };

const REQUEST_TYPES = ["viewing", "reserve"] as const;
type RequestType = (typeof REQUEST_TYPES)[number];

/** D8 request types the ad allows (`request_types` from the API, in a stable order). */
export function allowedRequestTypes(types: string[] | undefined): RequestType[] {
  return REQUEST_TYPES.filter((type) => (types ?? []).includes(type));
}

/** Seller box + contact actions on the ad detail (Figma 55:1477 right column). */
export function ContactPanel({ ad, sellerName, children }: { ad: Summary; sellerName: string; children?: ReactNode }) {
  const t = useTranslations("classifieds");
  const tc = useTranslations("common");
  const ta = useTranslations("portalPlus.ad");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const { isSignedIn } = useSession();
  const [phone, setPhone] = useState<string | null>(null);
  const [modal, setModal] = useState<"message" | "request" | "report" | null>(null);
  const [gate, setGate] = useState<{ contracts: RequiredContract[]; retry: () => void } | null>(null);
  const live = ad.status === "active";
  const requestTypes = allowedRequestTypes(ad.request_types);

  const reveal = useMutation({
    mutationFn: async () => (await api.revealPhone(market.slug, ad.number)).data.phone,
    onSuccess: setPhone,
    onError: (error) => {
      const contracts = requiredContracts(error);
      if (contracts) setGate({ contracts, retry: () => reveal.mutate() });
    },
  });

  const showPhone = () => {
    if (!isSignedIn) {
      router.push(`${href("/login")}?next=${encodeURIComponent(window.location.pathname)}`);
      return;
    }
    reveal.mutate();
  };

  const shop = ad.seller.shop;
  const broker = ad.seller.broker;

  return (
    <div className="flex flex-col gap-3">
      <section className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4">
        <div className="flex items-center gap-3">
          <span className="flex size-11 items-center justify-center rounded-full bg-brand-subtle text-body font-semibold text-brand-text">
            {sellerName.slice(0, 2).toUpperCase()}
          </span>
          <div className="min-w-0">
            <p className="truncate text-body font-semibold">{sellerName}</p>
            <p className="text-body-sm text-secondary">{t(`sellerType.${ad.seller.type === "shop" ? "shop" : ad.seller.type === "broker" ? "broker" : "individual"}`)}</p>
          </div>
        </div>
        {shop?.verified || broker?.verified ? (
          <Badge tone="info" className="self-start">
            <BadgeCheck className="size-3" /> {t("verifiedBusiness")}
          </Badge>
        ) : null}

        {live ? (
          <>
            {phone ? (
              <a href={`tel:${phone}`} className="flex h-11 items-center justify-center gap-2 rounded-md bg-brand font-semibold" dir="ltr">
                <Phone className="size-4" />
                {phone}
              </a>
            ) : (
              <Button size="lg" onClick={showPhone} disabled={reveal.isPending}>
                <Phone className="size-4" />
                {t("showPhone")}
              </Button>
            )}
            {reveal.error && !requiredContracts(reveal.error) ? <Alert tone="danger">{errorMessage(reveal.error, tc("error"))}</Alert> : null}
            {/* Figma 55:1477: Chat · WhatsApp (the number comes from the logged phone reveal). */}
            <div className="grid grid-cols-2 gap-2">
              <Button variant="outline" onClick={() => setModal("message")}>
                <MessageSquare className="size-4" />
                {t("chat")}
              </Button>
              {phone && whatsAppNumber(phone) ? (
                <a href={`https://wa.me/${whatsAppNumber(phone)}?text=${encodeURIComponent(ta("whatsAppText", { title: ad.title, number: ad.number }))}`} target="_blank" rel="noopener noreferrer" className={buttonClass("outline")}>
                  <WhatsAppIcon />
                  {ta("whatsApp")}
                </a>
              ) : (
                <Button variant="outline" onClick={showPhone} disabled={reveal.isPending} title={ta("whatsAppHint")}>
                  <WhatsAppIcon />
                  {ta("whatsApp")}
                </Button>
              )}
            </div>
          </>
        ) : (
          <Alert tone="warning">{t("notLive")}</Alert>
        )}
        {shop?.slug ? (
          <Link href={href(`/classified/shop/${encodeURIComponent(shop.slug)}`)} className="flex items-center gap-2 text-body-sm text-link">
            <Store className="size-4" />
            {t("visitShop")}
          </Link>
        ) : null}
        {ad.seller.member_since ?? shop?.member_since ?? broker?.member_since ? (
          <p className="text-caption text-secondary">{t("memberSince", { date: formatDay(ad.seller.member_since ?? shop?.member_since ?? broker?.member_since, market.locale) })}</p>
        ) : null}
        {shop?.rating_count ? (
          <p className="text-caption text-secondary">{t("shopRating", { rating: Number(shop.rating ?? 0).toFixed(1), count: shop.rating_count })}</p>
        ) : null}
        {broker?.slug ? (
          <Link href={href(`/brokers/${encodeURIComponent(broker.slug)}`)} className="text-body-sm text-link">
            {t("viewBroker")}
          </Link>
        ) : null}
      </section>

      {live && requestTypes.length ? (
        // Figma 55:1477 → 151:10194: the request modal's trigger under the seller card.
        <Button variant="dark" size="lg" className="w-full" onClick={() => setModal("request")}>
          <CalendarCheck className="size-4" />
          {requestTypes.length === 1 ? t(`request.types.${requestTypes[0]}`) : ta("requestViewingOrReserve")}
        </Button>
      ) : null}
      {live ? <FavouriteButton number={ad.number} withLabel className="justify-center border border-line py-2 shadow-none lg:hidden" /> : null}

      {children}

      <section className="rounded-lg bg-warning-subtle p-4">
        <h3 className="mb-2 text-body font-semibold">{t("safety.title")}</h3>
        <ul className="flex list-disc flex-col gap-1 ps-5 text-body-sm">
          <li>{t("safety.meet")}</li>
          <li>{t("safety.deposit")}</li>
          <li>{t("safety.inspect")}</li>
          <li>{t("safety.noPayment")}</li>
        </ul>
        {live ? (
          <button type="button" onClick={() => setModal("report")} className="mt-3 flex items-center gap-1.5 text-body-sm font-medium text-danger-text">
            <Flag className="size-4" />
            {t("report.cta")}
          </button>
        ) : null}
      </section>

      {modal === "message" ? <InquiryModal ad={ad} sellerName={sellerName} mode="message" onClose={() => setModal(null)} onContract={setGate} /> : null}
      {modal === "request" ? <InquiryModal ad={ad} sellerName={sellerName} mode="request" onClose={() => setModal(null)} onContract={setGate} /> : null}
      {modal === "report" ? <ReportModal number={ad.number} onClose={() => setModal(null)} /> : null}
      {gate ? (
        <ContractModal
          contracts={gate.contracts}
          onClose={() => setGate(null)}
          onAccepted={() => {
            const retry = gate.retry;
            setGate(null);
            retry();
          }}
        />
      ) : null}
    </div>
  );
}

/** wa.me wants the international number as digits only (the reveal returns E.164, QA-020). */
function whatsAppNumber(phone: string): string | null {
  const digits = phone.replace(/\D/g, "");
  return digits.length >= 8 ? digits : null;
}

function WhatsAppIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="size-4 fill-current">
      <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.2c0-.1-.2-.2-.5-.3Z" />
    </svg>
  );
}

const SLOTS = ["morning", "afternoon", "evening"] as const;
const SLOT_HOURS: Record<(typeof SLOTS)[number], number> = { morning: 10, afternoon: 14, evening: 18 };

function tomorrow(): string {
  const d = new Date(Date.now() + 86_400_000);
  return d.toISOString().slice(0, 10);
}

/**
 * Message the seller (`POST classifieds/ads/{number}/inquiries`), or the D8
 * viewing / reserve request (Figma 151:10194 → sent 151:10399,
 * `POST classifieds/ads/{number}/viewings` with `type`). No payment on Nawy;
 * guests too. The request types come from the ad's `request_types`.
 */
function InquiryModal({
  ad,
  sellerName,
  mode,
  onClose,
  onContract,
}: {
  ad: Summary;
  sellerName: string;
  mode: "message" | "request";
  onClose: () => void;
  onContract: (gate: { contracts: RequiredContract[]; retry: () => void }) => void;
}) {
  const t = useTranslations("classifieds");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const { user, isSignedIn } = useSession();
  const types = allowedRequestTypes(ad.request_types);
  const [form, setForm] = useState({ name: user?.name ?? "", email: user?.email ?? "", phone: "", message: "", type: (types[0] ?? "viewing") as RequestType, date: tomorrow(), slot: "morning" as (typeof SLOTS)[number] });
  const [consent, setConsent] = useState(true);
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const send = useMutation({
    mutationFn: async (): Promise<{ kind: "thread"; id: number } | { kind: "request"; id: number }> => {
      const contact = { name: form.name.trim(), email: form.email.trim(), phone: form.phone.trim() };
      if (mode === "request") {
        const [y, m, d] = form.date.split("-").map(Number);
        const preferred = new Date(y, (m ?? 1) - 1, d ?? 1, SLOT_HOURS[form.slot], 0, 0);
        const sent = (await api.requestAdVisit(market.slug, ad.number, { ...contact, type: form.type, preferred_at: preferred.toISOString(), message: form.message.trim() || undefined })).data;
        return { kind: "request", id: sent.id };
      }
      return { kind: "thread", id: (await api.sendInquiry(market.slug, ad.number, { ...contact, message: form.message.trim() })).data.thread_id };
    },
    onError: (error) => {
      const contracts = requiredContracts(error);
      if (contracts) onContract({ contracts, retry: () => send.mutate() });
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    send.mutate();
  };

  const err = (key: string) => fieldError(send.error, key);
  const generalError = send.error && !requiredContracts(send.error) && !Object.keys((send.error as { fields?: object }).fields ?? {}).length ? errorMessage(send.error, tc("error")) : null;

  if (send.isSuccess) {
    return (
      <Modal title={mode === "request" ? t("request.sentTitle") : t("inquiry.sentTitle")} onClose={onClose}>
        <div className="flex flex-col items-center gap-3 text-center">
          <CheckCircle2 className="size-12 text-success-text" />
          <p className="text-body">{mode === "request" ? t("request.sentBody", { seller: sellerName }) : t("inquiry.sentBody", { seller: sellerName })}</p>
          <p className="text-body-sm text-secondary">{t("request.reference", { id: send.data.id })}</p>
          <Alert tone="info" className="w-full text-start">
            {t("request.noPayment")}
          </Alert>
          <div className="flex w-full flex-col gap-2 sm:flex-row">
            {isSignedIn ? (
              <ButtonLink href={send.data.kind === "thread" ? `${href("/account/inquiries")}?thread=${send.data.id}` : `${href("/account/inquiries")}?tab=requests`} className="flex-1">
                {send.data.kind === "thread" ? t("inquiry.openChat") : t("request.openRequests")}
              </ButtonLink>
            ) : null}
            <Button variant="outline" onClick={onClose} className="flex-1">
              {t("request.backToAd")}
            </Button>
          </div>
        </div>
      </Modal>
    );
  }

  return (
    <Modal title={mode === "request" ? t("request.title") : t("inquiry.title")} onClose={onClose}>
      <p className="mb-4 text-body-sm text-secondary">
        {ad.title}
        {ad.price ? ` · ${formatMoney(ad.price)}` : ""} · {sellerName}
      </p>
      <form onSubmit={submit} className="flex flex-col gap-3">
        {mode === "request" ? (
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-label font-medium">{t("request.what")}</legend>
            {types.map((type) => (
              <label key={type} className="flex items-center gap-2 text-body">
                <input type="radio" name="type" checked={form.type === type} onChange={() => setForm((f) => ({ ...f, type }))} />
                {t(`request.types.${type}`)}
              </label>
            ))}
          </fieldset>
        ) : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("form.name")} error={err("name")}>
            <Input value={form.name} onChange={set("name")} required minLength={2} maxLength={120} autoComplete="name" />
          </Field>
          <Field label={t("form.email")} error={err("email")}>
            <Input type="email" value={form.email} onChange={set("email")} required autoComplete="email" dir="ltr" />
          </Field>
        </div>
        <Field label={t("form.phone")} error={err("phone")} hint={t("form.phoneHint", { country: market.country.name[market.locale] })}>
          <Input type="tel" value={form.phone} onChange={set("phone")} required maxLength={24} autoComplete="tel" dir="ltr" />
        </Field>
        {mode === "request" ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("request.date")}>
              <Input type="date" value={form.date} min={tomorrow()} onChange={set("date")} required />
            </Field>
            <Field label={t("request.time")}>
              <Select value={form.slot} onChange={set("slot")}>
                {SLOTS.map((slot) => (
                  <option key={slot} value={slot}>
                    {t(`request.slots.${slot}`)}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
        ) : null}
        <Field label={mode === "request" ? t("request.message") : t("inquiry.message")} error={err("message")}>
          <Textarea value={form.message} onChange={set("message")} required={mode === "message"} minLength={mode === "message" ? 2 : undefined} maxLength={3500} placeholder={t("inquiry.placeholder")} />
        </Field>
        {mode === "request" ? (
          <Alert tone="info">
            <span className="block font-semibold">{t("request.noPaymentTitle")}</span>
            {t("request.noPaymentBody", { seller: sellerName })}
          </Alert>
        ) : null}
        <label className="flex items-start gap-2 text-body-sm">
          <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-1" />
          {t("form.consent", { seller: sellerName })}
        </label>
        {generalError ? <Alert tone="danger">{generalError}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            {tc("cancel")}
          </Button>
          <Button type="submit" disabled={!consent || send.isPending}>
            {mode === "request" ? t("request.send") : t("inquiry.send")}
          </Button>
        </div>
        {!isSignedIn ? <p className="text-caption text-muted">{t("request.guestNote")}</p> : null}
      </form>
    </Modal>
  );
}

const REASONS = ["fraud", "wrong_category", "duplicate", "sold", "offensive", "other"] as const;

/** Report ad (Figma 98:12023) — `POST classifieds/ads/{number}/reports`. */
function ReportModal({ number, onClose }: { number: string; onClose: () => void }) {
  const t = useTranslations("classifieds.report");
  const tc = useTranslations("common");
  const market = useMarket();
  const [reason, setReason] = useState<(typeof REASONS)[number]>("fraud");
  const [details, setDetails] = useState("");
  const send = useMutation({ mutationFn: () => api.reportAd(market.slug, number, { reason, details: details.trim() || undefined }) });

  return (
    <Modal title={t("title")} onClose={onClose}>
      {send.isSuccess ? (
        <div className="flex flex-col items-center gap-3 text-center">
          <CheckCircle2 className="size-12 text-success-text" />
          <p className="text-body">{t("thanks")}</p>
          <Button onClick={onClose}>{tc("close")}</Button>
        </div>
      ) : (
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            send.mutate();
          }}
        >
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-label font-medium">{t("reason")}</legend>
            {REASONS.map((r) => (
              <label key={r} className="flex items-center gap-2 text-body">
                <input type="radio" name="reason" checked={reason === r} onChange={() => setReason(r)} />
                {t(`reasons.${r}`)}
              </label>
            ))}
          </fieldset>
          <Field label={t("details")}>
            <Textarea value={details} onChange={(e) => setDetails(e.target.value)} maxLength={1000} />
          </Field>
          {send.error ? <Alert tone="danger">{errorMessage(send.error, tc("error"))}</Alert> : null}
          <Button type="submit" variant="dark" disabled={send.isPending}>
            {t("submit")}
          </Button>
        </form>
      )}
    </Modal>
  );
}
