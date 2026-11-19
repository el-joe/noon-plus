"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { AlertTriangle, Minus, Plus, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Field, Input, Select, Textarea } from "@/components/ui/field";
import { Alert, Badge, Card, Spinner } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { requestTravelBooking, type TravelPackage } from "@/lib/api/travel";
import type { Money } from "@/lib/api/schemas";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { ContactFields, type Contact } from "./contact-fields";
import { adultRooms } from "./departure-picker";
import { addMoney, fieldErrorsOf, roomTypeLabel, timesMoney, TripImage } from "./shared";

type RoomLine = { room_type: string; travellers: number };

/**
 * Trip booking request (Figma 69:6370, guest + phone error 96:4188; MASTER_PLAN
 * D8): contact details, departure, rooms and travellers, optional names and
 * notes. No payment and no passport data — the agency confirms and is paid
 * directly. Sends `submit: true`, so the request goes straight to the agency.
 */
export function BookingForm({ pkg, initial }: { pkg: TravelPackage; initial: { departure?: number; room?: string; travellers?: number; children?: number } }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const { user } = useSession();
  const agency = pkg.agency ?? "";

  const open = pkg.departures.filter((d) => d.status !== "sold_out" && d.seats_left > 0);
  const [departureId, setDepartureId] = useState<number | null>((open.find((d) => d.id === initial.departure) ?? open[0])?.id ?? null);
  const departure = open.find((d) => d.id === departureId) ?? null;
  const roomChoices = adultRooms(departure);
  const childPrice = departure?.child_price_per_person ?? null;
  const defaultRoom = roomChoices.find((r) => r.type === initial.room)?.type ?? roomChoices[0]?.type ?? "";
  const [rooms, setRooms] = useState<RoomLine[]>([{ room_type: defaultRoom, travellers: Math.max(1, Math.min(20, initial.travellers ?? 1)) }]);
  const [contact, setContact] = useState<Contact>({ name: "", email: "", phone: "" });
  const [names, setNames] = useState<string[]>([]);
  const [message, setMessage] = useState("");
  const [kids, setKids] = useState(Math.max(0, Math.min(19, initial.children ?? 0)));
  const [agreed, setAgreed] = useState(false);
  const [mustAgree, setMustAgree] = useState(false);

  useEffect(() => {
    if (user) setContact((c) => ({ ...c, name: c.name || user.name || "", email: c.email || user.email }));
  }, [user]);

  // Keep room types valid when the departure changes.
  useEffect(() => {
    if (!departure) return;
    const choices = adultRooms(departure);
    setRooms((lines) => lines.map((l) => (choices.some((r) => r.type === l.room_type) ? l : { ...l, room_type: choices[0]?.type ?? "" })));
  }, [departure]);

  const adults = rooms.reduce((n, r) => n + (Number(r.travellers) || 0), 0);
  // QC-060: children only on a departure with a child price (otherwise everyone books as an adult).
  const children = childPrice ? kids : 0;
  const seats = adults + children;
  const maxSeats = Math.min(20, departure?.seats_left ?? 20);
  const priceOf = (type: string) => departure?.rooms.find((r) => r.type === type)?.price_per_person ?? null;
  const currency = departure?.rooms[0]?.price_per_person.currency ?? market.country.currency;
  const total = useMemo(
    () =>
      addMoney(
        rooms.reduce<Money>((sum, r) => {
          const line = timesMoney(priceOf(r.room_type), r.travellers);
          return line ? { amount: (Number(sum.amount) + Number(line.amount)).toFixed(line.amount.split(".")[1]?.length ?? 2), currency: line.currency } : sum;
        }, { amount: "0", currency }),
        children > 0 ? timesMoney(childPrice, children) : null,
      ),
    [rooms, departure, children], // eslint-disable-line react-hooks/exhaustive-deps
  );

  const send = useMutation({
    mutationFn: async () => {
      if (!departure) throw new ApiError(422, "not_allowed", t("travel.book.unavailable"));
      return (
        await requestTravelBooking(market.slug, departure.id, {
          ...contact,
          rooms: rooms.map((r) => ({ room_type: r.room_type, travellers: r.travellers })),
          ...(children > 0 ? { children } : {}),
          traveller_names: names.slice(0, seats).map((n) => n.trim()),
          message: message.trim() || undefined,
        })
      ).data;
    },
    onSuccess: (booking) => {
      client.invalidateQueries({ queryKey: ["travel-bookings"] });
      router.push(href(`/travel/bookings/${encodeURIComponent(booking.number)}/sent`));
    },
  });
  const errors = fieldErrorsOf(send.error);
  const errorCount = Object.keys(send.error instanceof ApiError ? send.error.fields : {}).length;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!agreed) {
      setMustAgree(true);
      return;
    }
    send.mutate();
  };

  const setRoom = (index: number, patch: Partial<RoomLine>) => setRooms((lines) => lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  if (open.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="warning">{t("travel.detail.noDepartures")}</Alert>
        <ButtonLink href={href(`/travel/${encodeURIComponent(pkg.slug)}`)} variant="outline" className="self-start">
          {t("travel.book.backToTrip")}
        </ButtonLink>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <div>
        <h1 className="text-h1 font-bold">{t("travel.book.title")}</h1>
        <p className="text-body text-secondary">{t("travel.book.subtitle", { agency })}</p>
      </div>

      {send.error && errorCount > 0 ? (
        <Alert tone="danger">
          <p className="font-semibold">{t("travel.book.checkFields", { count: errorCount })}</p>
          <p className="text-body-sm">{t("travel.book.checkFieldsHint")}</p>
        </Alert>
      ) : send.error ? (
        <Alert tone="danger">{errorMessage(send.error, t("common.error"))}</Alert>
      ) : null}

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="flex flex-col gap-4">
          <Card>
            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-h4 font-semibold">{t("travel.book.yourDetails")}</h2>
              {user ? <Badge tone="info">{t("travel.book.signedInAs", { email: user.email })}</Badge> : <Badge>{t("travel.book.bookingAsGuest")}</Badge>}
            </div>
            {!user ? <p className="mb-3 text-body-sm text-secondary">{t("travel.book.guestHint")}</p> : <div className="mb-3" />}
            <ContactFields value={contact} onChange={setContact} errors={errors} />
          </Card>

          <Card>
            <h2 className="mb-3 text-h4 font-semibold">{t("travel.book.tripDetails")}</h2>
            <div className="flex flex-col gap-3">
              <Field label={`${t("travel.book.departure")} *`}>
                <Select value={departureId ?? ""} onChange={(e) => setDepartureId(Number(e.target.value))}>
                  {open.map((d) => (
                    <option key={d.id} value={d.id}>
                      {t("travel.detail.departsReturns", { from: formatDay(d.departs_on, market.locale, true), to: formatDay(d.returns_on, market.locale, true) })} · {t("travel.detail.seatsLeft", { count: d.seats_left })}
                    </option>
                  ))}
                </Select>
              </Field>
              <p className="text-label font-medium">{t("travel.book.rooms")} *</p>
              {rooms.map((line, index) => (
                <div key={index} className="grid items-end gap-3 rounded-md border border-line p-3 sm:grid-cols-[1fr_auto_auto]">
                  <Field label={t("travel.book.roomN", { n: index + 1 })}>
                    <Select value={line.room_type} onChange={(e) => setRoom(index, { room_type: e.target.value })}>
                      {roomChoices.map((r) => (
                        <option key={r.type} value={r.type}>
                          {t("travel.detail.roomOption", { type: roomTypeLabel(t, r.type), price: formatMoney(r.price_per_person) })}
                        </option>
                      ))}
                    </Select>
                  </Field>
                  <div className="flex flex-col gap-1.5">
                    <span className="text-label font-medium">{t("travel.book.travellersInRoom")}</span>
                    <div className="flex items-center gap-2">
                      <Button size="sm" variant="outline" aria-label="−" disabled={line.travellers <= 1} onClick={() => setRoom(index, { travellers: line.travellers - 1 })}>
                        <Minus className="size-3.5" />
                      </Button>
                      <span className="w-6 text-center font-semibold">{line.travellers}</span>
                      <Button size="sm" variant="outline" aria-label="+" disabled={seats >= maxSeats} onClick={() => setRoom(index, { travellers: line.travellers + 1 })}>
                        <Plus className="size-3.5" />
                      </Button>
                    </div>
                  </div>
                  {rooms.length > 1 ? (
                    <Button size="sm" variant="ghost" aria-label={t("travel.book.removeRoom")} onClick={() => setRooms((lines) => lines.filter((_, i) => i !== index))}>
                      <Trash2 className="size-4" />
                    </Button>
                  ) : null}
                </div>
              ))}
              {errors.rooms ? <p className="text-body-sm text-danger-text">{errors.rooms}</p> : null}
              {seats >= maxSeats && departure && departure.seats_left < 20 ? <p className="text-body-sm text-secondary">{t("travel.detail.seatsLeft", { count: departure.seats_left })}</p> : null}
              {rooms.length < 10 && seats < maxSeats ? (
                <Button variant="link" className="self-start" onClick={() => setRooms((lines) => [...lines, { room_type: roomChoices[0]?.type ?? "", travellers: 1 }])}>
                  <Plus className="size-4" /> {t("travel.book.addRoom")}
                </Button>
              ) : null}
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-3">
                <div>
                  <p className="text-label font-medium">{t("travel.detail.children")}</p>
                  <p className="text-caption text-secondary">{childPrice ? t("travel.detail.childPrice", { price: formatMoney(childPrice) }) : t("travel.detail.noChildPrice")}</p>
                </div>
                <div className="flex items-center gap-2">
                  <Button size="sm" variant="outline" aria-label={t("travel.book.fewerChildren")} disabled={!childPrice || children <= 0} onClick={() => setKids(children - 1)}>
                    <Minus className="size-3.5" />
                  </Button>
                  <span className="w-6 text-center font-semibold">{children}</span>
                  <Button size="sm" variant="outline" aria-label={t("travel.book.moreChildren")} disabled={!childPrice || children >= 19 || seats >= maxSeats} onClick={() => setKids(children + 1)}>
                    <Plus className="size-3.5" />
                  </Button>
                </div>
              </div>
              {errors.children ? <p className="text-body-sm text-danger-text">{errors.children}</p> : null}
            </div>
          </Card>

          <Card>
            <h2 className="mb-1 text-h4 font-semibold">{t("travel.book.travellerNames")}</h2>
            <p className="mb-3 text-body-sm text-secondary">{t("travel.book.travellerNamesHint")}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              {Array.from({ length: seats }, (_, i) => (
                <Field key={i} label={i === 0 ? t("travel.book.travellerLead") : t("travel.book.traveller", { n: i + 1 })} error={errors[`traveller_names.${i}`]}>
                  <Input maxLength={160} value={names[i] ?? ""} placeholder={t("travel.book.travellerPlaceholder")} onChange={(e) => setNames((list) => Object.assign([...list], { [i]: e.target.value }))} />
                </Field>
              ))}
            </div>
            {errors.traveller_names ? <p className="mt-2 text-body-sm text-danger-text">{errors.traveller_names}</p> : null}
          </Card>

          <Card>
            <Field label={t("travel.book.notes")} error={errors.message} hint={`${message.length} / 1000`}>
              <Textarea maxLength={1000} value={message} placeholder={t("travel.book.notesPlaceholder")} onChange={(e) => setMessage(e.target.value)} />
            </Field>
          </Card>

          <Card className="flex flex-col gap-3">
            <label className="flex items-start gap-2 text-body-sm">
              <input
                type="checkbox"
                checked={agreed}
                onChange={(e) => {
                  setAgreed(e.target.checked);
                  if (e.target.checked) setMustAgree(false);
                }}
                className="mt-0.5 size-4 accent-brand"
              />
              <span>{t("travel.book.agree", { agency })}</span>
            </label>
            {mustAgree ? (
              <p className="flex items-center gap-1.5 text-body-sm text-danger-text">
                <AlertTriangle className="size-4" />
                {t("travel.book.mustAgree")}
              </p>
            ) : null}
            <div className="flex flex-col-reverse gap-3 sm:flex-row">
              <ButtonLink href={href(`/travel/${encodeURIComponent(pkg.slug)}`)} variant="ghost">
                <span className="rtl:rotate-180">‹</span> {t("travel.book.backToTrip")}
              </ButtonLink>
              <Button type="submit" size="lg" className="flex-1" disabled={send.isPending || !departure}>
                {send.isPending ? <Spinner /> : null}
                {t("travel.book.send")}
              </Button>
            </div>
            <p className="text-caption text-secondary">{t("travel.book.terms")}</p>
          </Card>
        </div>

        <aside className="lg:sticky lg:top-32">
          <Card className="flex flex-col gap-3">
            <h2 className="text-h4 font-semibold">{t("travel.book.summary")}</h2>
            <TripImage className="aspect-[16/9] w-full rounded-md" label={pkg.image?.alt ?? pkg.title} src={pkg.image?.url ?? pkg.cover_url} />
            <div>
              <p className="text-body font-semibold">{pkg.title}</p>
              {pkg.agency ? <p className="text-caption text-secondary">{t("travel.byAgency", { agency: pkg.agency })}</p> : null}
            </div>
            {departure ? (
              <dl className="flex flex-col gap-1.5 border-t border-line pt-3 text-body-sm">
                <Row label={t("travel.book.departureLabel")} value={formatDay(departure.departs_on, market.locale, true)} />
                <Row label={t("travel.book.returnLabel")} value={formatDay(departure.returns_on, market.locale, true)} />
                <Row label={t("travel.book.travellersLabel")} value={children > 0 ? t("travel.book.adultsChildren", { adults, children }) : String(seats)} />
              </dl>
            ) : null}
            <ul className="flex flex-col gap-1 border-t border-line pt-3 text-body-sm">
              {rooms.map((r, i) => (
                <li key={i} className="flex justify-between gap-2">
                  <span className="text-secondary">{t("travel.book.lineLabel", { count: r.travellers, type: roomTypeLabel(t, r.room_type), price: formatMoney(priceOf(r.room_type)) })}</span>
                  <span dir="ltr">{formatMoney(timesMoney(priceOf(r.room_type), r.travellers))}</span>
                </li>
              ))}
              {children > 0 && childPrice ? (
                <li className="flex justify-between gap-2">
                  <span className="text-secondary">{t("travel.book.childLine", { count: children, price: formatMoney(childPrice) })}</span>
                  <span dir="ltr">{formatMoney(timesMoney(childPrice, children))}</span>
                </li>
              ) : null}
            </ul>
            <p className="flex items-center justify-between gap-2 border-t border-line pt-3">
              <span className="flex items-center gap-2 text-body font-semibold">
                {t("travel.book.estimatedTotal")} <Badge>{t("travel.book.estimate")}</Badge>
              </span>
              <span className={clsx("text-h3 font-bold")} dir="ltr">
                {formatMoney(total)}
              </span>
            </p>
            <div className="rounded-md bg-info-subtle p-3 text-body-sm">
              <p className="font-semibold">{t("travel.book.payAgencyTitle")}</p>
              <p className="text-secondary">{t("travel.book.payAgencyBody", { agency })}</p>
            </div>
            <p className="text-caption text-secondary">{t("travel.book.pricesNote")}</p>
          </Card>
        </aside>
      </div>
    </form>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-2">
      <dt className="text-secondary">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}
