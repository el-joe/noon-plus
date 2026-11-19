import type { Metadata } from "next";
import { AlertTriangle, BadgeCheck, ChevronRight, Home, MapPin, UserRound } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Alert, Card, Container, Skeleton } from "@/components/ui/misc";
import { furnishingLabel, propertyTypeLabel, purposeLabel, PropertyCardView, PropertyImage, rentPeriodLabel } from "@/features/real-estate/shared";
import { ViewingRequestForm } from "@/features/real-estate/viewing-form";
import type { MarketParams } from "@/features/travel/page-types";
import { orNull } from "@/lib/api/client";
import { getProperty, type PropertyDetail } from "@/lib/api/real-estate";
import { formatDay, formatMoney, humanize } from "@/lib/format";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";

type Props = { params: MarketParams<{ number: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { market: slug, number } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const ad = await orNull(getProperty(market.slug, decodeURIComponent(number)).then((r) => r.data)).catch(() => null);
  // Only a property ad names this page (a car ad here is a 404, QA-012).
  if (!ad?.property) return { title: getT(market.locale)("realEstate.title") };
  return { title: ad.title, description: ad.description?.slice(0, 160) ?? undefined };
}

/** `/property/[number]` — property detail (Figma 70:6770) with the D8 viewing request. */
export default function PropertyPage({ params }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[720px] w-full" />}>
        <PropertyContent params={params} />
      </Suspense>
    </Container>
  );
}

async function PropertyContent({ params }: Props) {
  const { market: slug, number } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const ad = await orNull(getProperty(market.slug, decodeURIComponent(number)).then((r) => r.data));
  if (!ad || !ad.property) notFound();
  return <PropertyView market={market} ad={ad} />;
}

function list(value: unknown): string[] {
  if (Array.isArray(value)) return value.map((v) => (typeof v === "string" ? v : typeof v === "object" && v ? String((v as Record<string, unknown>).name ?? (v as Record<string, unknown>).label ?? "") : String(v))).filter(Boolean);
  if (value && typeof value === "object") return Object.entries(value).filter(([, v]) => v === true).map(([k]) => humanize(k));
  if (typeof value === "string") return value.split(/[\n,]/).map((s) => s.trim()).filter(Boolean);
  return [];
}

function PropertyView({ market, ad }: { market: Market; ad: PropertyDetail }) {
  const t = getT(market.locale);
  const p = ad.property!;
  const photos = ad.media.filter((m) => m.type === "photo");
  const video = ad.media.find((m) => m.type === "video");
  const amenities = list(p.features);
  const plan = list(p.payment_plan);
  const facts: [string, ReactNode][] = [
    [t("realEstate.detail.type"), propertyTypeLabel(t, p.property_type) ?? "—"],
    [t("realEstate.detail.beds"), p.bedrooms === 0 ? t("realEstate.studio") : (p.bedrooms ?? "—")],
    [t("realEstate.detail.baths"), p.bathrooms ?? "—"],
    [t("realEstate.detail.size"), p.area_sqm ? t("realEstate.detail.sqm", { value: Number(p.area_sqm).toLocaleString("en") }) : "—"],
  ];
  if (p.furnishing) facts.push([t("realEstate.detail.furnishing"), furnishingLabel(t, p.furnishing)]);
  if (p.rent_period && p.purpose === "rent") facts.push([t("realEstate.detail.rentPeriod"), rentPeriodLabel(t, p.rent_period)]);
  const regulatory: [string, string][] = [
    [t("realEstate.detail.permit"), p.permit_number ?? ""],
    [t("realEstate.detail.authority"), p.permit_authority ?? ""],
    [t("realEstate.detail.developer"), p.developer ?? ""],
    [t("realEstate.detail.completion"), p.completion_on ? formatDay(p.completion_on, market.locale) : ""],
  ].filter((r): r is [string, string] => !!r[1]);
  const seller = ad.seller.broker ?? ad.seller.shop;
  const sellerHref = ad.seller.broker?.slug ? `/${market.slug}/brokers/${encodeURIComponent(ad.seller.broker.slug)}` : ad.seller.shop?.slug ? `/${market.slug}/classified/shop/${encodeURIComponent(ad.seller.shop.slug)}` : null;
  const sellerType = (["broker", "shop", "individual"] as const).find((s) => s === ad.seller.type) ?? "individual";
  const live = ad.status === "active";

  return (
    <div className="flex flex-col gap-5">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <Link href={`/${market.slug}/property`} className="hover:text-ink">
          {t("realEstate.title")}
        </Link>
        {p.purpose ? (
          <>
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <Link href={`/${market.slug}/property?purpose=${encodeURIComponent(p.purpose)}`} className="hover:text-ink">
              {purposeLabel(t, p.purpose)}
            </Link>
          </>
        ) : null}
        {ad.area || ad.city ? (
          <>
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <span className="text-ink">{ad.area ?? ad.city}</span>
          </>
        ) : null}
      </nav>

      <div className="grid gap-3 md:grid-cols-[2fr_1fr]">
        <PropertyImage src={photos[0]?.url} alt={ad.title} className="aspect-[16/10] w-full rounded-lg" />
        <div className="hidden grid-rows-2 gap-3 md:grid">
          <PropertyImage src={photos[1]?.url} alt="" className="size-full rounded-lg" />
          <PropertyImage src={photos[2]?.url} alt="" className="size-full rounded-lg" />
        </div>
      </div>

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="flex flex-col gap-4">
          {!live ? <Alert tone="warning">{t("realEstate.detail.sold")}</Alert> : null}
          <Card>
            <div className="mb-2 flex flex-wrap gap-2">
              {ad.is_featured ? <span className="rounded-xs bg-brand px-1.5 py-0.5 text-caption font-medium">{t("realEstate.featured")}</span> : null}
              {ad.verified_business ? (
                <span className="inline-flex items-center gap-1 rounded-xs bg-info-subtle px-1.5 py-0.5 text-caption text-link">
                  <BadgeCheck className="size-3" />
                  {t("realEstate.verified")}
                </span>
              ) : null}
              {video ? <span className="rounded-xs bg-subtle px-1.5 py-0.5 text-caption">{t("realEstate.videoTour")}</span> : null}
              {p.purpose ? <span className="rounded-xs bg-subtle px-1.5 py-0.5 text-caption">{purposeLabel(t, p.purpose)}</span> : null}
            </div>
            {ad.price ? (
              <p className="text-h2 font-bold" dir="ltr">
                {formatMoney(ad.price)}
                {p.purpose === "rent" && p.rent_period ? <span className="text-body font-normal text-secondary"> / {rentPeriodLabel(t, p.rent_period)}</span> : null}
              </p>
            ) : null}
            {ad.is_negotiable ? <p className="text-body-sm text-secondary">{t("realEstate.negotiable")}</p> : null}
            <h1 className="mt-1 text-h3 font-semibold">{ad.title}</h1>
            <p className="mt-1 flex flex-wrap items-center gap-x-2 text-body-sm text-secondary">
              <MapPin className="size-3.5" />
              {[ad.area, ad.city].filter(Boolean).join(", ")}
              <span>· {t("realEstate.detail.adId", { number: ad.number })}</span>
              {ad.posted_at ? <span>· {t("realEstate.detail.listed", { date: formatDay(ad.posted_at, market.locale) })}</span> : null}
              {ad.views ? <span>· {t("realEstate.detail.views", { count: ad.views })}</span> : null}
            </p>
            <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {facts.map(([label, value]) => (
                <div key={label}>
                  <dt className="text-caption text-secondary">{label}</dt>
                  <dd className="text-body font-semibold">{value}</dd>
                </div>
              ))}
            </dl>
          </Card>

          {amenities.length > 0 ? (
            <Card>
              <h2 className="mb-3 text-h4 font-semibold">{t("realEstate.detail.amenities")}</h2>
              <div className="flex flex-wrap gap-2">
                {amenities.map((a) => (
                  <span key={a} className="rounded-full border border-line px-3 py-1 text-body-sm">
                    {a}
                  </span>
                ))}
              </div>
            </Card>
          ) : null}

          {ad.description ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("realEstate.detail.description")}</h2>
              <p className="whitespace-pre-line text-body text-secondary">{ad.description}</p>
            </Card>
          ) : null}

          {video ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("realEstate.videoTour")}</h2>
              <video src={video.url} controls playsInline preload="metadata" className="aspect-video w-full rounded-md bg-ink" />
            </Card>
          ) : null}

          {ad.location ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("realEstate.detail.location")}</h2>
              <a
                href={`https://www.openstreetmap.org/?mlat=${ad.location.lat}&mlon=${ad.location.lng}#map=16/${ad.location.lat}/${ad.location.lng}`}
                target="_blank"
                rel="noopener noreferrer"
                className="flex aspect-[3/1] w-full items-center justify-center gap-2 rounded-md bg-info-subtle text-body-sm font-medium text-link"
              >
                <MapPin className="size-5" />
                {t("realEstate.detail.openMap")}
              </a>
            </Card>
          ) : null}

          {plan.length > 0 || regulatory.length > 0 ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("realEstate.detail.regulatory")}</h2>
              <dl className="flex flex-col divide-y divide-line text-body-sm">
                {regulatory.map(([label, value]) => (
                  <div key={label} className="flex justify-between gap-3 py-1.5">
                    <dt className="text-secondary">{label}</dt>
                    <dd className="font-medium">{value}</dd>
                  </div>
                ))}
                {plan.length > 0 ? (
                  <div className="flex justify-between gap-3 py-1.5">
                    <dt className="text-secondary">{t("realEstate.detail.paymentPlan")}</dt>
                    <dd className="text-end font-medium">{plan.join(" · ")}</dd>
                  </div>
                ) : null}
              </dl>
            </Card>
          ) : null}
        </div>

        <aside className="flex flex-col gap-3 lg:sticky lg:top-32">
          <Card className="flex items-center gap-3">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-brand-text">
              <UserRound className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="flex items-center gap-1 truncate text-body font-semibold">
                {seller?.name ?? t(`realEstate.detail.seller.${sellerType}`)}
                {seller?.verified ? <BadgeCheck className="size-4 text-link" /> : null}
              </p>
              <p className="text-caption text-secondary">{t(`realEstate.detail.seller.${sellerType}`)}</p>
              {ad.exclusive_marketer ? <p className="text-caption text-brand-text">{t("realEstate.detail.exclusive", { name: ad.exclusive_marketer.name ?? "" })}</p> : null}
            </div>
            {sellerHref ? (
              <ButtonLink href={sellerHref} variant="outline" size="sm">
                {t("realEstate.detail.viewProfile")}
              </ButtonLink>
            ) : null}
          </Card>
          {live && ad.request_types.length > 0 ? (
            <Card>
              <h2 className="mb-3 text-h4 font-semibold">{t("realEstate.viewing.title")}</h2>
              <ViewingRequestForm number={ad.number} types={ad.request_types} />
            </Card>
          ) : null}
          <div className="rounded-lg bg-warning-subtle p-4 text-body-sm">
            <p className="mb-2 flex items-center gap-2 font-semibold text-warning-text">
              <AlertTriangle className="size-4" />
              {t("realEstate.detail.staySafe")}
            </p>
            <ul className="flex list-disc flex-col gap-1 ps-5 text-secondary">
              <li>{t("realEstate.detail.tip1")}</li>
              <li>{t("realEstate.detail.tip2")}</li>
              <li>{t("realEstate.detail.tip3")}</li>
              <li>{t("realEstate.detail.tip4")}</li>
            </ul>
          </div>
        </aside>
      </div>

      {ad.similar.length > 0 ? (
        <section>
          <h2 className="mb-3 text-h3 font-semibold">{t("realEstate.detail.similar")}</h2>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {ad.similar.slice(0, 4).map((s) => (
              <PropertyCardView key={s.number} ad={s} market={market} />
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}
