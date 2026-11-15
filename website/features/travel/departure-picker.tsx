"use client";

import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useMemo, useState } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Field, Select } from "@/components/ui/field";
import type { TravelDeparture } from "@/lib/api/travel";
import { formatDay, formatMoney } from "@/lib/format";
import { useHref, useMarket } from "@/lib/market-context";
import { roomTypeLabel, timesMoney } from "./shared";

/** Trip detail booking box (Figma 69:6093): departure, room, travellers, estimate → request form. */
export function DeparturePicker({ slug, departures }: { slug: string; departures: TravelDeparture[] }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const open = departures.filter((d) => d.status !== "sold_out" && d.seats_left > 0);
  const [departureId, setDepartureId] = useState<number | null>(open[0]?.id ?? null);
  const departure = departures.find((d) => d.id === departureId) ?? null;
  const [room, setRoom] = useState<string>(departure?.rooms[0]?.type ?? "");
  const [travellers, setTravellers] = useState(1);

  const roomPrice = departure?.rooms.find((r) => r.type === room) ?? departure?.rooms[0] ?? null;
  const minPrice = useMemo(() => {
    const prices = departures.flatMap((d) => d.rooms.map((r) => r.price_per_person));
    return prices.reduce<(typeof prices)[number] | null>((low, p) => (!low || Number(p.amount) < Number(low.amount) ? p : low), null);
  }, [departures]);
  const maxTravellers = Math.min(20, departure?.seats_left ?? 1);
  const count = Math.min(travellers, maxTravellers);
  const total = roomPrice ? timesMoney(roomPrice.price_per_person, count) : null;

  const pick = (d: TravelDeparture) => {
    setDepartureId(d.id);
    if (!d.rooms.some((r) => r.type === room)) setRoom(d.rooms[0]?.type ?? "");
  };

  return (
    <div className="flex flex-col gap-3 rounded-lg border-2 border-brand bg-surface p-4">
      {minPrice !== null ? (
        <p>
          <span className="text-h3 font-bold" dir="ltr">
            {formatMoney(roomPrice ? roomPrice.price_per_person : minPrice)}
          </span>{" "}
          <span className="text-body-sm text-secondary">{t("travel.perPerson")}</span>
        </p>
      ) : null}
      {departures.length === 0 ? (
        <p className="text-body-sm text-secondary">{t("travel.detail.noDepartures")}</p>
      ) : (
        <>
          <p className="text-label font-medium">{t("travel.detail.chooseDeparture")}</p>
          <div className="flex flex-col gap-2" role="radiogroup" aria-label={t("travel.detail.chooseDeparture")}>
            {departures.map((d) => {
              const soldOut = d.status === "sold_out" || d.seats_left <= 0;
              const active = d.id === departureId;
              return (
                <button
                  key={d.id}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  disabled={soldOut}
                  onClick={() => pick(d)}
                  className={clsx(
                    "flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-start text-body-sm",
                    active ? "border-brand bg-brand-subtle font-semibold" : "border-line hover:bg-subtle",
                    soldOut && "cursor-not-allowed opacity-50",
                  )}
                >
                  <span>{t("travel.detail.departsReturns", { from: formatDay(d.departs_on, market.locale, true), to: formatDay(d.returns_on, market.locale, true) })}</span>
                  <span className={clsx("shrink-0 text-caption", soldOut ? "text-secondary" : d.seats_left <= 5 ? "text-danger-text" : "text-secondary")}>
                    {soldOut ? t("travel.soldOut") : t("travel.detail.seatsLeft", { count: d.seats_left })}
                  </span>
                </button>
              );
            })}
          </div>
          {departure && departure.rooms.length > 0 ? (
            <Field label={t("travel.detail.room")}>
              <Select value={roomPrice?.type ?? ""} onChange={(e) => setRoom(e.target.value)}>
                {departure.rooms.map((r) => (
                  <option key={r.type} value={r.type}>
                    {t("travel.detail.roomOption", { type: roomTypeLabel(t, r.type), price: formatMoney(r.price_per_person) })}
                  </option>
                ))}
              </Select>
            </Field>
          ) : null}
          {departure ? (
            <Field label={t("travel.detail.travellers")}>
              <Select value={count} onChange={(e) => setTravellers(Number(e.target.value))}>
                {Array.from({ length: maxTravellers }, (_, i) => i + 1).map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </Select>
            </Field>
          ) : null}
          {total ? (
            <p className="flex items-center justify-between border-t border-line pt-3 text-body-sm">
              <span>{t("travel.detail.totalFor", { count })}</span>
              <span className="text-body font-bold" dir="ltr">
                {formatMoney(total)}
              </span>
            </p>
          ) : null}
          {departure ? (
            <ButtonLink href={`${href(`/travel/${encodeURIComponent(slug)}/book`)}?departure=${departure.id}&room=${encodeURIComponent(roomPrice?.type ?? "")}&travellers=${count}`} size="lg" className="w-full">
              {t("travel.detail.requestToBook")}
            </ButtonLink>
          ) : null}
        </>
      )}
    </div>
  );
}
