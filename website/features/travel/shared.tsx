import clsx from "clsx";
import { MapPin, Moon, Plane } from "lucide-react";
import Link from "next/link";
import type { ApiError } from "@/lib/api/client";
import type { TravelPackageCard } from "@/lib/api/travel";
import type { Money } from "@/lib/api/schemas";
import { formatAmount, formatMoney } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

export const TRIP_TYPES = ["umrah", "hajj", "holiday", "honeymoon", "adventure", "business"] as const;
export type TripType = (typeof TRIP_TYPES)[number];

export function isTripType(value: string | null | undefined): value is TripType {
  return !!value && (TRIP_TYPES as readonly string[]).includes(value);
}

/** API decimals (e.g. "245.0000") in the market currency, rounded to its minor unit for display. */
export function marketPrice(amount: string | null | undefined, market: Market): string {
  if (amount === null || amount === undefined || amount === "") return "";
  const n = Number(amount);
  if (!Number.isFinite(n)) return amount;
  return `${market.country.currency} ${formatAmount(n.toFixed(market.country.decimals))}`;
}

/** Money of a room/package price times a count (client-side estimate; the agency confirms the quote). */
export function timesMoney(price: Money | null | undefined, count: number): Money | null {
  if (!price) return null;
  const decimals = price.amount.split(".")[1]?.length ?? 2;
  return { amount: (Number(price.amount) * count).toFixed(decimals), currency: price.currency };
}

/** Trip picture: the package cover (`image.url` / `cover_url`), or the neutral placeholder when none. */
export function TripImage({ className, label, src }: { className?: string; label?: string | null; src?: string | null }) {
  if (src) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={src} alt={label ?? ""} loading="lazy" className={clsx("object-cover", className)} />;
  }
  return (
    <div className={clsx("flex flex-col items-center justify-center gap-2 bg-ink text-white/80", className)} role="img" aria-label={label ?? ""}>
      <Plane className="size-10" strokeWidth={1.5} />
    </div>
  );
}

/** Trip card (Figma Travel home 69:5767 package card). */
export function TripCard({ pkg, market }: { pkg: TravelPackageCard; market: Market }) {
  const t = getT(market.locale);
  const soldOut = pkg.status === "sold_out";
  const type = isTripType(pkg.trip_type) ? t(`travel.types.${pkg.trip_type}`) : pkg.trip_type;
  return (
    <Link href={`/${market.slug}/travel/${encodeURIComponent(pkg.slug)}`} className="group flex h-full flex-col overflow-hidden rounded-lg border border-line bg-surface hover:shadow-sm">
      <div className="relative aspect-[16/10] w-full overflow-hidden">
        <TripImage className="size-full" label={pkg.image?.alt ?? pkg.title} src={pkg.image?.url ?? pkg.cover_url} />
        {type ? <span className="absolute start-2 top-2 rounded-xs bg-brand px-1.5 py-0.5 text-caption font-medium text-ink">{type}</span> : null}
        {soldOut ? <span className="absolute end-2 top-2 rounded-xs bg-ink px-1.5 py-0.5 text-caption font-medium text-white">{t("travel.soldOut")}</span> : null}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-3">
        <h3 className="line-clamp-2 text-body font-semibold">{pkg.title}</h3>
        <p className="flex flex-wrap items-center gap-x-2 text-caption text-secondary">
          {pkg.destination ? (
            <span className="inline-flex items-center gap-1">
              <MapPin className="size-3" />
              {pkg.destination}
            </span>
          ) : null}
          {pkg.nights ? (
            <span className="inline-flex items-center gap-1">
              <Moon className="size-3" />
              {t("travel.nights", { count: pkg.nights })}
            </span>
          ) : null}
        </p>
        {pkg.agency ? <p className="text-caption text-secondary">{t("travel.byAgency", { agency: pkg.agency })}</p> : null}
        {pkg.from_price ? (
          <p className="mt-auto pt-1 text-body-sm">
            <span className="text-secondary">{t("travel.from")} </span>
            <span className="font-bold" dir="ltr">
              {formatMoney(pkg.from_price)}
            </span>
            <span className="text-secondary"> {t("travel.perPerson")}</span>
          </p>
        ) : null}
      </div>
    </Link>
  );
}

/** 422 `validation_failed` → first message per field (`rooms.0.travellers` → `rooms`). */
export function fieldErrorsOf(error: unknown): Record<string, string> {
  const fields = (error as ApiError | null)?.fields ?? {};
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(fields)) {
    const root = key.split(".")[0];
    if (!out[key] && messages[0]) out[key] = messages[0];
    if (!out[root] && messages[0]) out[root] = messages[0];
  }
  return out;
}

export const ROOM_TYPES = ["single", "double", "twin", "triple", "quad", "family"] as const;
type RoomTypeKey = (typeof ROOM_TYPES)[number];

/** Room type label: known types are translated, others shown as entered by the agency. */
export function roomTypeLabel(translate: (key: `travel.roomType.${RoomTypeKey}`) => string, type: string): string {
  const key = type.toLowerCase();
  if ((ROOM_TYPES as readonly string[]).includes(key)) return translate(`travel.roomType.${key as RoomTypeKey}`);
  return type.replace(/[_-]+/g, " ").replace(/^\w/, (c) => c.toUpperCase());
}
