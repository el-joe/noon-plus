"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { CheckCircle2, ChevronRight, Home, Info, Mail } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { Modal } from "@/components/layout/modal";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Card, EmptyState, Skeleton, Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import {
  cancelTravelBooking,
  claimTravelBooking,
  resendBookingLinks,
  getTravelBooking,
  MODIFICATION_TYPES,
  requestTravelModification,
  submitTravelBooking,
  type TravelBooking,
} from "@/lib/api/travel";
import { formatDateTime, formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { fieldErrorsOf, marketPrice, roomTypeLabel } from "./shared";
import { BookingStatusChip } from "./status-chip";

export const bookingKey = (market: string, number: string) => ["travel-booking", market, number] as const;

function useBooking(number: string) {
  const market = useMarket();
  return useQuery({
    queryKey: bookingKey(market.slug, number),
    retry: false,
    queryFn: async () => (await getTravelBooking(market.slug, number)).data,
  });
}

/** Opens a guest booking from the email link (`?token=`) by remembering it on this device first. */
function useClaimFromLink(number: string, onClaimed: () => void) {
  const market = useMarket();
  const params = useSearchParams();
  const token = params.get("token");
  const done = useRef(false);
  const claim = useMutation({ mutationFn: (code: string) => claimTravelBooking(market.slug, number, code), onSuccess: onClaimed });
  useEffect(() => {
    if (token && !done.current) {
      done.current = true;
      claim.mutate(token);
      window.history.replaceState(null, "", window.location.pathname);
    }
  }, [token]); // eslint-disable-line react-hooks/exhaustive-deps
  return claim;
}

/** `/travel/bookings/[number]` — booking status (Figma 98:10830, chips 145:6927). */
export function BookingStatusView({ number }: { number: string }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const booking = useBooking(number);
  const claim = useClaimFromLink(number, () => client.invalidateQueries({ queryKey: bookingKey(market.slug, number) }));

  if (booking.isLoading || claim.isPending) return <Skeleton className="h-[560px] w-full" />;
  if (booking.error || !booking.data) {
    if (booking.error instanceof ApiError && booking.error.status === 404) return <FindBooking number={number} />;
    return <Alert tone="danger">{errorMessage(booking.error, t("common.error"))}</Alert>;
  }
  const b = booking.data;

  return (
    <div className="flex flex-col gap-4">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label="Breadcrumb">
        <Link href={href("/")} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <Link href={href("/travel/bookings")} className="hover:text-ink">
          {t("travel.my.title")}
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span className="text-ink">{b.number}</span>
      </nav>
      <div>
        <h1 className="flex flex-wrap items-center gap-3 text-h2 font-bold">
          {t("travel.status.title", { number: b.number })} <BookingStatusChip status={b.status} />
        </h1>
        <p className="text-body-sm text-secondary">
          {t("travel.status.summaryLine", { package: b.package.title ?? b.package.slug, agency: b.agency ?? "", date: formatDay(b.departs_on, market.locale, true) })}
        </p>
      </div>
      <Timeline booking={b} />
      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Details booking={b} />
        <div className="flex flex-col gap-4">
          <Card>
            <h2 className="mb-3 text-h4 font-semibold">{t("travel.status.priceEstimate")}</h2>
            <ul className="flex flex-col gap-1 text-body-sm">
              {(b.rooms ?? []).map((r, i) => (
                <li key={i} className="flex justify-between gap-2">
                  <span className="text-secondary">
                    {r.travellers} × {roomTypeLabel(t, r.room_type)}
                  </span>
                  {r.line_total ? <span dir="ltr">{marketPrice(r.line_total, market)}</span> : null}
                </li>
              ))}
            </ul>
            <p className="mt-2 flex items-center justify-between border-t border-line pt-2">
              <span className="text-body font-semibold">{t("travel.book.estimatedTotal")}</span>
              <span className="text-h3 font-bold" dir="ltr">
                {formatMoney(b.quoted_total)}
              </span>
            </p>
            <div className="mt-3 rounded-md bg-info-subtle p-3 text-body-sm">
              <p className="font-semibold">{t("travel.book.payAgencyTitle")}</p>
              <p className="text-secondary">{t("travel.book.payAgencyBody", { agency: b.agency ?? "" })}</p>
            </div>
          </Card>
          <Actions booking={b} />
        </div>
      </div>
    </div>
  );
}

const STEPS = ["held", "requested", "confirmed", "completed"] as const;

function Timeline({ booking }: { booking: TravelBooking }) {
  const t = useTranslations("travel.status");
  const market = useMarket();
  const reached = STEPS.indexOf(booking.status as (typeof STEPS)[number]);
  const off = reached < 0;
  return (
    <Card>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-h4 font-semibold">{(["held", "requested", "confirmed", "completed", "rejected", "cancelled", "hold_expired"] as const).includes(booking.status as "held") ? t(`headline.${booking.status as "held"}`) : booking.status}</h2>
        {booking.status === "requested" ? <span className="text-caption text-secondary">{t("replyHint")}</span> : null}
      </div>
      <ol className="grid grid-cols-4 gap-2">
        {STEPS.map((step, i) => {
          const done = !off && i <= reached;
          const current = !off && i === reached;
          return (
            <li key={step} className="flex flex-col gap-1.5">
              <div className="flex items-center">
                <span className={clsx("size-3 shrink-0 rounded-full", current ? "bg-brand" : done ? "bg-success" : "bg-line-strong")} />
                {i < STEPS.length - 1 ? <span className={clsx("h-0.5 flex-1", !off && i < reached ? "bg-success" : "bg-line")} /> : null}
              </div>
              <span className={clsx("text-body-sm font-medium", done ? "text-ink" : "text-muted")}>{t(`steps.${step}`)}</span>
              <span className="text-caption text-secondary">{t(`steps.${step}Hint`)}</span>
            </li>
          );
        })}
      </ol>
      <p className="mt-4 flex flex-wrap items-center gap-2 text-caption text-secondary">
        {t("otherOutcomes")}
        <BookingStatusChip status="rejected" />
        <BookingStatusChip status="cancelled" />
        <BookingStatusChip status="hold_expired" />
      </p>
      {booking.status === "held" && booking.hold_expires_at ? (
        <Alert tone="warning" className="mt-3">
          {t("holdExpires", { time: formatDateTime(booking.hold_expires_at, market.locale, market.country.timeZone) })}
        </Alert>
      ) : null}
    </Card>
  );
}

function Details({ booking: b }: { booking: TravelBooking }) {
  const t = useTranslations();
  const market = useMarket();
  const names = (b.travellers ?? []).filter((n): n is string => !!n);
  const rows: [string, string][] = [
    [t("travel.status.name"), b.contact.name ?? ""],
    [t("travel.status.email"), b.contact.email ?? ""],
    [t("travel.status.phone"), b.contact.phone ?? ""],
    [t("travel.status.departure"), t("travel.detail.departsReturns", { from: formatDay(b.departs_on, market.locale, true), to: formatDay(b.returns_on, market.locale, true) })],
    [t("travel.status.rooms"), (b.rooms ?? []).map((r) => `${r.travellers} × ${roomTypeLabel(t, r.room_type)}`).join(", ")],
    [t("travel.status.travellers"), t("travel.my.travellersCount", { count: b.seats })],
  ];
  if (names.length) rows.push([t("travel.status.travellerNames"), names.join(", ")]);
  if (b.confirmation_number) rows.push([t("travel.status.confirmationNumber"), b.confirmation_number]);
  if (b.decision_reason) rows.push([t("travel.status.agencyNote"), b.decision_reason]);
  return (
    <Card>
      <h2 className="mb-3 text-h4 font-semibold">{t("travel.status.requestDetails")}</h2>
      <dl className="flex flex-col divide-y divide-line text-body-sm">
        {rows.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4 py-2">
            <dt className="text-secondary">{label}</dt>
            <dd className="text-end font-medium" dir={label === t("travel.status.phone") || label === t("travel.status.email") ? "ltr" : undefined}>
              {value}
            </dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}

function Actions({ booking }: { booking: TravelBooking }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [dialog, setDialog] = useState<"cancel" | "change" | null>(null);
  const refresh = (data: TravelBooking) => {
    client.setQueryData(bookingKey(market.slug, booking.number), data);
    client.invalidateQueries({ queryKey: ["travel-bookings"] });
  };
  const submit = useMutation({ mutationFn: async () => (await submitTravelBooking(market.slug, booking.number)).data, onSuccess: refresh });
  const canCancel = ["held", "requested", "confirmed"].includes(booking.status);
  const canChange = ["requested", "confirmed"].includes(booking.status);

  return (
    <Card className="flex flex-col gap-2">
      <h2 className="text-h4 font-semibold">{t("travel.status.needSomething")}</h2>
      {submit.error ? <Alert tone="danger">{errorMessage(submit.error, t("common.error"))}</Alert> : null}
      {booking.status === "held" ? (
        <Button onClick={() => submit.mutate()} disabled={submit.isPending}>
          {submit.isPending ? <Spinner /> : null}
          {t("travel.status.submitHeld")}
        </Button>
      ) : null}
      <ButtonLink href={href(`/travel/${encodeURIComponent(booking.package.slug)}`)} variant="outline">
        {t("travel.status.viewTrip")}
      </ButtonLink>
      {canChange ? (
        <Button variant="outline" onClick={() => setDialog("change")}>
          {t("travel.status.requestChange")}
        </Button>
      ) : null}
      {canCancel ? (
        <>
          <Button className="bg-danger text-white hover:brightness-95" onClick={() => setDialog("cancel")}>
            {t("travel.status.cancel")}
          </Button>
          <p className="text-caption text-secondary">{t("travel.status.cancelHint")}</p>
        </>
      ) : null}
      {dialog === "cancel" ? <CancelDialog booking={booking} onDone={refresh} onClose={() => setDialog(null)} /> : null}
      {dialog === "change" ? <ChangeDialog booking={booking} onClose={() => setDialog(null)} /> : null}
    </Card>
  );
}

function CancelDialog({ booking, onDone, onClose }: { booking: TravelBooking; onDone: (b: TravelBooking) => void; onClose: () => void }) {
  const t = useTranslations();
  const market = useMarket();
  const [reason, setReason] = useState("");
  const cancel = useMutation({
    mutationFn: async () => (await cancelTravelBooking(market.slug, booking.number, reason.trim())).data,
    onSuccess: (b) => {
      onDone(b);
      onClose();
    },
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    cancel.mutate();
  };
  return (
    <Modal title={t("travel.status.cancel")} onClose={onClose}>
      <form onSubmit={submit} className="flex flex-col gap-3">
        <p className="text-body-sm text-secondary">{t("travel.status.cancelHint")}</p>
        {cancel.error ? <Alert tone="danger">{errorMessage(cancel.error, t("common.error"))}</Alert> : null}
        <Field label={t("travel.status.cancelReason")}>
          <Textarea required minLength={3} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <div className="flex gap-2">
          <Button variant="outline" className="flex-1" onClick={onClose}>
            {t("travel.status.keep")}
          </Button>
          <Button type="submit" className="flex-1 bg-danger text-white" disabled={cancel.isPending || reason.trim().length < 3}>
            {cancel.isPending ? <Spinner /> : null}
            {t("travel.status.cancelConfirm")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function ChangeDialog({ booking, onClose }: { booking: TravelBooking; onClose: () => void }) {
  const t = useTranslations();
  const market = useMarket();
  const [type, setType] = useState<(typeof MODIFICATION_TYPES)[number]>("change_departure");
  const [details, setDetails] = useState("");
  const change = useMutation({ mutationFn: () => requestTravelModification(market.slug, booking.number, type, { note: details.trim() }) });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    change.mutate();
  };
  return (
    <Modal title={t("travel.status.requestChange")} onClose={onClose}>
      {change.isSuccess ? (
        <Alert tone="success">{t("travel.status.changeSent")}</Alert>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-3">
          {change.error ? <Alert tone="danger">{errorMessage(change.error, t("common.error"))}</Alert> : null}
          <Field label={t("travel.status.changeType")}>
            <Select value={type} onChange={(e) => setType(e.target.value as (typeof MODIFICATION_TYPES)[number])}>
              {MODIFICATION_TYPES.map((m) => (
                <option key={m} value={m}>
                  {t(`travel.status.changeTypes.${m}`)}
                </option>
              ))}
            </Select>
          </Field>
          <Field label={t("travel.status.changeDetails")}>
            <Textarea required maxLength={1000} value={details} onChange={(e) => setDetails(e.target.value)} />
          </Field>
          <Button type="submit" disabled={change.isPending || !details.trim()}>
            {change.isPending ? <Spinner /> : null}
            {t("travel.status.sendChange")}
          </Button>
        </form>
      )}
    </Modal>
  );
}

/** Guest access by booking number + access code (from the request's link) when this device has no token. */
export function FindBooking({ number = "" }: { number?: string }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const [value, setValue] = useState(number);
  const [code, setCode] = useState("");
  const claim = useMutation({
    mutationFn: async () => (await claimTravelBooking(market.slug, value.trim(), code.trim())).data,
    onSuccess: (b) => {
      client.setQueryData(bookingKey(market.slug, b.number), b);
      client.invalidateQueries({ queryKey: ["travel-bookings"] });
      if (b.number !== number) window.location.href = href(`/travel/bookings/${encodeURIComponent(b.number)}`);
    },
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    claim.mutate();
  };
  return (
    <div className="mx-auto flex max-w-lg flex-col gap-4">
      <EmptyState icon={<Info className="size-10" />} title={t("travel.status.notFound")} body={t("travel.status.notFoundBody")} />
      <Card>
        <form onSubmit={submit} className="flex flex-col gap-3">
          {claim.error ? <Alert tone="danger">{errorMessage(claim.error, t("travel.status.notFound"))}</Alert> : null}
          <Field label={t("travel.status.numberLabel")}>
            <Input required dir="ltr" value={value} maxLength={40} onChange={(e) => setValue(e.target.value)} />
          </Field>
          <Field label={t("travel.status.accessCode")}>
            <Input required dir="ltr" value={code} maxLength={120} autoComplete="off" onChange={(e) => setCode(e.target.value)} />
          </Field>
          <Button type="submit" disabled={claim.isPending || !value.trim() || !code.trim()}>
            {claim.isPending ? <Spinner /> : null}
            {t("travel.status.find")}
          </Button>
          <ButtonLink href={`${href("/login")}?next=${encodeURIComponent(href(`/travel/bookings/${encodeURIComponent(number || value)}`))}`} variant="link" className="self-center">
            {t("travel.status.signIn")}
          </ButtonLink>
        </form>
      </Card>
      <ResendBookingLink />
    </div>
  );
}

/** "Resend my booking link" (`POST travel/bookings/resend-link`): always 202, reveals nothing. */
export function ResendBookingLink() {
  const t = useTranslations();
  const market = useMarket();
  const { user } = useSession();
  const [email, setEmail] = useState("");
  const send = useMutation({ mutationFn: async (value: string) => (await resendBookingLinks(market.slug, value)).data });
  useEffect(() => {
    if (user?.email) setEmail((e) => e || user.email);
  }, [user]);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (email.trim()) send.mutate(email.trim());
  };
  return (
    <Card>
      <h2 className="text-h4 font-semibold">{t("travel.resend.title")}</h2>
      <p className="mb-3 text-body-sm text-secondary">{t("travel.resend.body")}</p>
      {send.isSuccess ? (
        <Alert tone="success">{t("travel.resend.sent", { email: send.variables ?? email })}</Alert>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-3">
          {send.error ? <Alert tone="danger">{errorMessage(send.error, t("common.error"))}</Alert> : null}
          <Field label={t("travel.resend.email")} error={fieldErrorsOf(send.error).email}>
            <Input type="email" required dir="ltr" autoComplete="email" value={email} maxLength={255} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Button type="submit" variant="outline" disabled={send.isPending || !email.trim()}>
            {send.isPending ? <Spinner /> : null}
            {t("travel.resend.send")}
          </Button>
        </form>
      )}
    </Card>
  );
}

/** `/travel/bookings/[number]/sent` — request sent (Figma 96:4554). */
export function BookingSent({ number }: { number: string }) {
  const t = useTranslations();
  const href = useHref();
  const booking = useBooking(number);
  if (booking.isLoading) return <Skeleton className="h-96 w-full" />;
  const b = booking.data;
  if (!b) return <FindBooking number={number} />;
  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <Card className="flex flex-col items-center gap-3 py-8 text-center">
        <CheckCircle2 className="size-14 text-success" />
        <h1 className="text-h2 font-bold">{t("travel.sent.title")}</h1>
        <BookingStatusChip status={b.status} />
        <p className="max-w-md text-body text-secondary">{t("travel.sent.body", { agency: b.agency ?? "", number: b.number })}</p>
        {b.contact.email ? (
          <p className="flex items-center gap-2 text-body-sm text-secondary">
            <Mail className="size-4" />
            {t("travel.sent.emailNote", { email: b.contact.email })}
          </p>
        ) : null}
      </Card>
      <Card>
        <h2 className="mb-3 text-h4 font-semibold">{t("travel.sent.nextSteps")}</h2>
        <ol className="flex flex-col gap-2 text-body-sm">
          {(["step1", "step2", "step3"] as const).map((step, i) => (
            <li key={step} className="flex items-start gap-3">
              <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-caption font-semibold text-brand-text">{i + 1}</span>
              {t(`travel.sent.${step}`)}
            </li>
          ))}
        </ol>
      </Card>
      <div className="flex flex-col gap-3 sm:flex-row">
        <ButtonLink href={href(`/travel/bookings/${encodeURIComponent(b.number)}`)} className="flex-1">
          {t("travel.sent.viewStatus")}
        </ButtonLink>
        <ButtonLink href={href("/travel")} variant="outline" className="flex-1">
          {t("travel.sent.browse")}
        </ButtonLink>
      </div>
    </div>
  );
}
