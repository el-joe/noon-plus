import type { Metadata } from "next";
import { Check, ChevronRight, Home, Info, Star, X } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import { Card, Container, SectionHeader, Skeleton } from "@/components/ui/misc";
import { DeparturePicker } from "@/features/travel/departure-picker";
import { AskAgency } from "@/features/travel/inquiry-form";
import type { MarketParams } from "@/features/travel/page-types";
import { isTripType, TripCard, TripImage, TripSponsoredLabel } from "@/features/travel/shared";
import { itineraryDays, refundTiers, textItems } from "@/features/travel/trip-text";
import { orNull } from "@/lib/api/client";
import { getTravelPackage, type TravelPackage } from "@/lib/api/travel";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";

type Props = { params: MarketParams<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { market: slug, slug: tripSlug } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const t = getT(market.locale);
  const pkg = await orNull(getTravelPackage(market.slug, tripSlug).then((r) => r.data)).catch(() => null);
  return { title: pkg?.title ?? t("travel.title"), description: pkg?.description?.slice(0, 160) ?? t("travel.metaDescription") };
}

/** `/travel/[slug]` — trip detail (Figma 69:6093): departures, request to book (D8, no payment), ask the agency. */
export default function TripPage({ params }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <TripContent params={params} />
      </Suspense>
    </Container>
  );
}

async function TripContent({ params }: Props) {
  const { market: slug, slug: tripSlug } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const pkg = await orNull(getTravelPackage(market.slug, tripSlug).then((r) => r.data));
  if (!pkg) notFound();
  return <TripView market={market} pkg={pkg} />;
}

function TripView({ market, pkg }: { market: Market; pkg: TravelPackage }) {
  const t = getT(market.locale);
  const included = textItems(pkg.inclusions);
  const excluded = textItems(pkg.exclusions);
  const days = itineraryDays(pkg.itinerary);
  const tiers = refundTiers(pkg.cancellation_policy);
  const type = isTripType(pkg.trip_type) ? t(`travel.types.${pkg.trip_type}`) : pkg.trip_type;

  return (
    <div className="flex flex-col gap-5">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <Link href={`/${market.slug}/travel`} className="hover:text-ink">
          {t("travel.title")}
        </Link>
        {type && pkg.trip_type ? (
          <>
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <Link href={`/${market.slug}/travel/search?trip_type=${encodeURIComponent(pkg.trip_type)}`} className="hover:text-ink">
              {type}
            </Link>
          </>
        ) : null}
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span className="line-clamp-1 text-ink">{pkg.title}</span>
      </nav>

      <TripGallery pkg={pkg} />

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="flex flex-col gap-4">
          <Card>
            <div className="mb-2 flex flex-wrap gap-2">
              <TripSponsoredLabel pkg={pkg} market={market} />
              {type ? <span className="rounded-xs bg-brand-subtle px-1.5 py-0.5 text-caption font-medium text-brand-text">{type}</span> : null}
              {pkg.nights ? <span className="rounded-xs bg-subtle px-1.5 py-0.5 text-caption">{t("travel.nights", { count: pkg.nights })}</span> : null}
              {pkg.destination ? <span className="rounded-xs bg-info-subtle px-1.5 py-0.5 text-caption text-link">{pkg.destination}</span> : null}
            </div>
            <h1 className="text-h2 font-bold">{pkg.title}</h1>
            <TripStats market={market} pkg={pkg} />
          </Card>

          {pkg.description ? (
            <Card>
              <h2 className="mb-2 text-h4 font-semibold">{t("travel.detail.about")}</h2>
              <p className="whitespace-pre-line text-body text-secondary">{pkg.description}</p>
            </Card>
          ) : null}

          {included.length > 0 || excluded.length > 0 ? (
            <Card>
              <h2 className="mb-3 text-h4 font-semibold">{t("travel.detail.included")}</h2>
              <ul className="grid gap-2 text-body-sm sm:grid-cols-2">
                {included.map((item, i) => (
                  <li key={`in-${i}`} className="flex items-start gap-2">
                    <Check className="mt-0.5 size-4 shrink-0 text-success" />
                    {item}
                  </li>
                ))}
                {excluded.map((item, i) => (
                  <li key={`ex-${i}`} className="flex items-start gap-2 text-muted">
                    <X className="mt-0.5 size-4 shrink-0" />
                    <span>
                      <span className="sr-only">{t("travel.detail.notIncluded")}: </span>
                      {item}
                    </span>
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}

          {days.length > 0 ? (
            <Card>
              <h2 className="mb-3 text-h4 font-semibold">{t("travel.detail.itinerary")}</h2>
              <ol className="flex flex-col gap-3">
                {days.map((d, i) => (
                  <li key={i} className="grid grid-cols-[72px_1fr] gap-3 text-body-sm">
                    <span className="font-semibold text-brand-text">{t("travel.detail.day", { day: d.day })}</span>
                    <span>
                      <span className="block font-medium">{d.title}</span>
                      {d.details ? <span className="text-secondary">{d.details}</span> : null}
                    </span>
                  </li>
                ))}
              </ol>
            </Card>
          ) : null}

          <Card>
            <h2 className="mb-2 text-h4 font-semibold">{t("travel.detail.policy")}</h2>
            <ul className="flex list-disc flex-col gap-1 ps-5 text-body-sm text-secondary">
              {tiers.map((tier) => (
                <li key={tier.days}>{t("travel.detail.refundTier", { percent: tier.percent, days: tier.days })}</li>
              ))}
              <li className="font-medium text-ink">{t("travel.detail.noPaymentPolicy")}</li>
            </ul>
          </Card>
        </div>

        <aside className="flex flex-col gap-3 lg:sticky lg:top-32">
          <DeparturePicker slug={pkg.slug} departures={pkg.departures} />
          <AskAgency slug={pkg.slug} agency={pkg.agency ?? ""} />
          <p className="flex items-start gap-2 rounded-md bg-info-subtle p-3 text-caption">
            <Info className="mt-0.5 size-4 shrink-0 text-link" />
            {t("travel.detail.noPaymentPolicy")}
          </p>
        </aside>
      </div>

      {pkg.related.length > 0 ? (
        <section data-testid="trip-related">
          <SectionHeader title={t("travel.related")} />
          {/* D14: server order — promoted similar trips come first. */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {pkg.related.map((item) => (
              <TripCard key={item.slug} pkg={item} market={market} />
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}

/**
 * QC-060: 1 + 2 gallery (Figma 69:6093). Uses `gallery[]`, falling back to the
 * cover; the side tiles stay neutral placeholders when there are fewer pictures.
 */
function TripGallery({ pkg }: { pkg: TravelPackage }) {
  const cover = pkg.image?.url ?? pkg.cover_url ?? null;
  const pictures = pkg.gallery.length > 0 ? pkg.gallery : cover ? [{ url: cover, alt: pkg.image?.alt ?? pkg.title }] : [];
  const [main, ...rest] = pictures;
  return (
    <div className="grid gap-3 md:grid-cols-[2fr_1fr]">
      <TripImage className="aspect-[16/9] w-full rounded-lg" label={main?.alt ?? pkg.title} src={main?.url} />
      <div className="hidden grid-rows-2 gap-3 md:grid">
        <TripImage className="size-full min-h-0 rounded-lg" label={rest[0]?.alt ?? ""} src={rest[0]?.url} />
        <TripImage className="size-full min-h-0 rounded-lg" label={rest[1]?.alt ?? ""} src={rest[1]?.url} />
      </div>
    </div>
  );
}

/** QC-060 stats line: "By {agency} · ★ 4.9 (612 trips) · 1,200 travellers". */
function TripStats({ market, pkg }: { market: Market; pkg: TravelPackage }) {
  const t = getT(market.locale);
  const stats = pkg.stats;
  const rating = stats?.rating;
  const average = rating === null || rating === undefined ? null : typeof rating === "number" ? rating : rating.average === null ? null : Number(rating.average);
  const parts: ReactNode[] = [];
  if (pkg.agency) parts.push(t("travel.byAgency", { agency: pkg.agency }));
  if (average !== null && Number.isFinite(average) && average > 0) {
    parts.push(
      <span key="rating" className="inline-flex items-center gap-1">
        <Star className="size-3.5 fill-brand text-brand" aria-hidden />
        {t("travel.stats.rating", { rating: average.toFixed(1) })}
      </span>,
    );
  }
  if (stats && stats.agency_trips > 0) parts.push(t("travel.stats.agencyTrips", { count: stats.agency_trips }));
  if (stats && stats.travellers > 0) parts.push(t("travel.stats.travellers", { count: stats.travellers }));
  if (parts.length === 0) return null;
  return (
    <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-body-sm text-secondary">
      {parts.map((part, i) => (
        <span key={i} className="inline-flex items-center gap-1.5">
          {i > 0 ? <span aria-hidden>·</span> : null}
          {part}
        </span>
      ))}
    </p>
  );
}
