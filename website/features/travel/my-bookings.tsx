"use client";

import { useQuery } from "@tanstack/react-query";
import { CalendarCheck, Plane } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { ButtonLink } from "@/components/ui/button";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { getMyTravelBookings, getTravelBooking, type TravelBooking } from "@/lib/api/travel";
import { formatDay, formatMoney } from "@/lib/format";
import { errorMessage, useMounted, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { readGuestBookingNumbers } from "./booking-cookies";
import { FindBooking } from "./booking-status";
import { BookingStatusChip } from "./status-chip";

type Row = TravelBooking & { guest: boolean };

/**
 * "My bookings" (Figma 49:2757): the customer's trip requests with status
 * chips, plus guest requests made on this device (their access tokens sit in
 * httpOnly cookies set by /api/travel-booking).
 */
export function MyTravelBookings({ withFind = false }: { withFind?: boolean }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const mounted = useMounted();
  const { isSignedIn, isLoading: sessionLoading } = useSession();

  const query = useQuery({
    queryKey: ["travel-bookings", market.slug, isSignedIn],
    enabled: mounted && !sessionLoading,
    queryFn: async (): Promise<Row[]> => {
      const own = isSignedIn ? (await getMyTravelBookings(market.slug)).data.map((b) => ({ ...b, guest: false })) : [];
      const numbers = readGuestBookingNumbers(market.country.code).filter((n) => !own.some((b) => b.number === n));
      const guest = await Promise.all(
        numbers.map((n) =>
          getTravelBooking(market.slug, n)
            .then((r) => ({ ...r.data, guest: true }))
            .catch((e: unknown) => {
              if (e instanceof ApiError && e.status === 404) return null;
              throw e;
            }),
        ),
      );
      return [...own, ...guest.filter((b): b is Row => b !== null)];
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-h2 font-bold">{t("travel.my.title")}</h1>
        <p className="text-body-sm text-secondary">{t("travel.my.subtitle")}</p>
      </div>
      {query.isLoading || !mounted ? <Skeleton className="h-48 w-full" /> : null}
      {query.error ? <Alert tone="danger">{errorMessage(query.error, t("common.error"))}</Alert> : null}
      {query.data && query.data.length === 0 ? (
        <EmptyState icon={<Plane className="size-10" />} title={t("travel.my.empty")} action={<ButtonLink href={href("/travel")}>{t("travel.my.browse")}</ButtonLink>} />
      ) : null}
      {query.data?.map((b) => (
        <Card key={b.number}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="flex flex-wrap items-center gap-2 text-body font-semibold">
                {b.number} <BookingStatusChip status={b.status} />
              </p>
              <p className="text-body-sm">{b.package.title}</p>
              <p className="text-caption text-secondary">
                {[b.agency, t("travel.my.departs", { date: formatDay(b.departs_on, market.locale, true) }), t("travel.my.travellersCount", { count: b.seats })].filter(Boolean).join(" · ")}
              </p>
              {b.guest ? <p className="mt-1 text-caption text-secondary">{t("travel.my.guestOnDevice")}</p> : null}
            </div>
            <div className="flex flex-col items-end gap-2">
              <span className="text-body font-bold" dir="ltr">
                {formatMoney(b.quoted_total)}
              </span>
              <Link href={href(`/travel/bookings/${encodeURIComponent(b.number)}`)} className="inline-flex items-center gap-1 text-body-sm font-medium text-link hover:underline">
                <CalendarCheck className="size-4" />
                {t("travel.my.view")}
              </Link>
            </div>
          </div>
        </Card>
      ))}
      {withFind ? (
        <section className="mt-4 flex flex-col gap-2">
          <h2 className="text-h4 font-semibold">{t("travel.my.findBooking")}</h2>
          <FindBooking />
        </section>
      ) : null}
    </div>
  );
}
