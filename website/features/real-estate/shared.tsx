import clsx from "clsx";
import { BadgeCheck, Building2, Camera, MapPin, Video } from "lucide-react";
import Link from "next/link";
import type { PropertyCard as PropertyCardData } from "@/lib/api/real-estate";
import { PROPERTY_TYPES, PURPOSES } from "@/lib/api/real-estate";
import { formatMoney, humanize } from "@/lib/format";
import { getT, type ServerT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

export function purposeLabel(t: ServerT, value: string | null | undefined): string | null {
  if (!value) return null;
  return (PURPOSES as readonly string[]).includes(value) ? t(`realEstate.purpose.${value as (typeof PURPOSES)[number]}`) : humanize(value);
}

export function propertyTypeLabel(t: ServerT, value: string | null | undefined): string | null {
  if (!value) return null;
  return (PROPERTY_TYPES as readonly string[]).includes(value) ? t(`realEstate.types.${value as (typeof PROPERTY_TYPES)[number]}`) : humanize(value);
}

const FURNISHING = ["furnished", "semi", "unfurnished"] as const;
const RENT_PERIODS = ["yearly", "monthly", "weekly", "daily"] as const;

/** QA-033 / QA-034: localised furnishing (`furnished | semi | unfurnished`). */
export function furnishingLabel(t: ServerT, value: string | null | undefined): string | null {
  if (!value) return null;
  return (FURNISHING as readonly string[]).includes(value) ? t(`realEstate.furnishing.${value as (typeof FURNISHING)[number]}`) : humanize(value);
}

/** QA-033 / QA-034: localised rent period (`yearly | monthly | weekly | daily`). */
export function rentPeriodLabel(t: ServerT, value: string | null | undefined): string | null {
  if (!value) return null;
  return (RENT_PERIODS as readonly string[]).includes(value) ? t(`realEstate.rentPeriod.${value as (typeof RENT_PERIODS)[number]}`) : humanize(value);
}

const PROPERTY_FACT_LABELS = {
  purpose: "purpose",
  property_type: "type",
  bedrooms: "beds",
  bathrooms: "baths",
  area_sqm: "size",
  furnishing: "furnishing",
  rent_period: "rentPeriod",
  permit_number: "permit",
  permit_authority: "authority",
  developer: "developer",
  completion_on: "completion",
  payment_plan: "paymentPlan",
  features: "amenities",
} as const;

/** Label + localised value of one `property.*` field of a classified ad (QA-033). */
export function propertyFact(t: ServerT, locale: "en" | "ar", key: string, value: unknown, fallback: (v: unknown) => string): [string, string] {
  const labelKey = PROPERTY_FACT_LABELS[key as keyof typeof PROPERTY_FACT_LABELS];
  const label = labelKey ? t(`realEstate.detail.${labelKey}`) : humanize(key);
  const text = typeof value === "string" ? value : null;
  switch (key) {
    case "purpose":
      return [label, purposeLabel(t, text) ?? fallback(value)];
    case "property_type":
      return [label, propertyTypeLabel(t, text) ?? fallback(value)];
    case "furnishing":
      return [label, furnishingLabel(t, text) ?? fallback(value)];
    case "rent_period":
      return [label, rentPeriodLabel(t, text) ?? fallback(value)];
    case "bedrooms":
      return [label, Number(value) === 0 ? t("realEstate.studio") : fallback(value)];
    case "area_sqm":
      return [label, t("realEstate.detail.sqm", { value: Number(value).toLocaleString("en") })];
    default:
      return [label, fallback(value)];
  }
}

/** Photo, or the neutral placeholder when the ad has none. */
export function PropertyImage({ src, alt, className }: { src: string | null | undefined; alt: string; className?: string }) {
  if (src && /^(https?:)?\//.test(src)) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={src} alt={alt} loading="lazy" className={clsx("object-cover", className)} />;
  }
  return (
    <div className={clsx("flex items-center justify-center bg-subtle text-muted", className)} role="img" aria-label={alt}>
      <Building2 className="size-10" strokeWidth={1.5} />
    </div>
  );
}

function specText(value: unknown): string | null {
  if (value === null || value === undefined || value === "") return null;
  if (typeof value === "boolean") return null;
  if (typeof value === "object") return null;
  return String(value);
}

/** Property card (Figma Real estate results 70:6418). */
export function PropertyCardView({ ad, market }: { ad: PropertyCardData; market: Market }) {
  const t = getT(market.locale);
  const specs = ad.key_specs && !Array.isArray(ad.key_specs) ? Object.entries(ad.key_specs).map(([k, v]) => [k, specText(v)] as const).filter(([, v]) => v) : [];
  return (
    <Link href={`/${market.slug}/property/${encodeURIComponent(ad.number)}`} className="group flex h-full flex-col overflow-hidden rounded-lg border border-line bg-surface hover:shadow-sm">
      <div className="relative aspect-[4/3] w-full overflow-hidden">
        <PropertyImage src={ad.image} alt={ad.title} className="size-full" />
        <div className="absolute start-2 top-2 flex gap-1">
          {ad.is_featured ? <span className="rounded-xs bg-brand px-1.5 py-0.5 text-caption font-medium text-ink">{t("realEstate.featured")}</span> : null}
          {ad.has_video ? (
            <span className="inline-flex items-center gap-1 rounded-xs bg-ink/80 px-1.5 py-0.5 text-caption text-white">
              <Video className="size-3" />
              {t("realEstate.videoTour")}
            </span>
          ) : null}
        </div>
        {ad.image_count > 0 ? (
          <span className="absolute bottom-2 end-2 inline-flex items-center gap-1 rounded-xs bg-ink/70 px-1.5 py-0.5 text-caption text-white">
            <Camera className="size-3" />
            {ad.image_count}
          </span>
        ) : null}
      </div>
      <div className="flex flex-1 flex-col gap-1 p-3">
        {ad.price ? (
          <p className="text-body-lg font-bold" dir="ltr">
            {formatMoney(ad.price)}
          </p>
        ) : null}
        <h3 className="line-clamp-2 text-body font-medium">{ad.title}</h3>
        <PropertyFactsLine facts={ad.property} t={t} />
        {specs.length > 0 && !ad.property ? <p className="line-clamp-1 text-caption text-secondary">{specs.map(([k, v]) => `${humanize(k)}: ${v}`).join(" · ")}</p> : null}
        <p className="mt-auto flex items-center gap-1 pt-1 text-caption text-secondary">
          <MapPin className="size-3" />
          {[ad.area, ad.city].filter(Boolean).join(", ")}
          {ad.verified_business ? (
            <span className="ms-auto inline-flex items-center gap-0.5 text-link">
              <BadgeCheck className="size-3.5" />
              {t("realEstate.verified")}
            </span>
          ) : null}
        </p>
      </div>
    </Link>
  );
}

type FactsInput = { bedrooms?: number | string | null; bathrooms?: number | string | null; area_sqm?: number | string | null; property_type?: string | null } | null | undefined;

/** "3 beds · 2 baths · 120 m²" from the card's `property` facts (round 2). */
export function propertyFacts(t: ServerT, facts: FactsInput): string[] {
  if (!facts) return [];
  const out: string[] = [];
  const type = propertyTypeLabel(t, facts.property_type);
  if (type) out.push(type);
  if (facts.bedrooms !== null && facts.bedrooms !== undefined && facts.bedrooms !== "") out.push(Number(facts.bedrooms) === 0 ? t("realEstate.studio") : t("realEstate.facts.beds", { count: Number(facts.bedrooms) }));
  if (facts.bathrooms !== null && facts.bathrooms !== undefined && facts.bathrooms !== "") out.push(t("realEstate.facts.baths", { count: Number(facts.bathrooms) }));
  if (facts.area_sqm !== null && facts.area_sqm !== undefined && facts.area_sqm !== "") out.push(t("realEstate.facts.area", { value: String(Number(facts.area_sqm)) }));
  return out;
}

export function PropertyFactsLine({ facts, t, className }: { facts: FactsInput; t: ServerT; className?: string }) {
  const parts = propertyFacts(t, facts);
  if (parts.length === 0) return null;
  return <p className={clsx("line-clamp-1 text-caption text-secondary", className)}>{parts.join(" · ")}</p>;
}
