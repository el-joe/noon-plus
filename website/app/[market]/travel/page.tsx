import type { Metadata } from "next";
import { Building2, Plane } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Container, EmptyState, SectionHeader, Skeleton } from "@/components/ui/misc";
import { TravelSearchForm } from "@/features/travel/search-form";
import { TRIP_TYPES, TripCard } from "@/features/travel/shared";
import { getTravelPackages, type TravelPackageCard } from "@/lib/api/travel";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";
import type { MarketParams, SearchParams } from "@/features/travel/page-types";

export async function generateMetadata({ params }: { params: MarketParams; searchParams: SearchParams }): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("travel.title"), description: t("travel.metaDescription") };
}

/** `/travel` — Travel home (Figma 69:5767): search, trip types, packages by type, licensed agencies. */
export default async function TravelHomePage({ params }: { params: MarketParams; searchParams: SearchParams }) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return (
    <Container className="flex flex-col gap-6 py-4">
      <section className="flex flex-col gap-4 rounded-xl bg-ink p-5 text-white md:p-8">
        <h1 className="text-h1 font-bold">{t("travel.heroTitle", { country: market.country.name[market.locale] })}</h1>
        <p className="text-body opacity-80">{t("travel.tagline")}</p>
        <TravelSearchForm market={market} />
      </section>
      <nav className="scrollbar-none flex gap-2 overflow-x-auto" aria-label={t("travel.search.tripType")}>
        <Link href={`/${market.slug}/travel/search`} className="shrink-0 rounded-full bg-ink px-3 py-1 text-body-sm font-medium text-white">
          {t("travel.types.all")}
        </Link>
        {TRIP_TYPES.map((type) => (
          <Link key={type} href={`/${market.slug}/travel/search?trip_type=${type}`} className="shrink-0 rounded-full border border-line bg-surface px-3 py-1 text-body-sm hover:bg-subtle">
            {t(`travel.types.${type}`)}
          </Link>
        ))}
      </nav>
      <Suspense fallback={<Skeleton className="h-[520px] w-full" />}>
        <TravelSections market={market} />
      </Suspense>
    </Container>
  );
}

async function TravelSections({ market }: { market: Market }) {
  const t = getT(market.locale);
  const packages = await getTravelPackages(market.slug)
    .then((r) => r.data)
    .catch(() => [] as TravelPackageCard[]);

  if (packages.length === 0) {
    return <EmptyState icon={<Plane className="size-10" />} title={t("travel.title")} body={t("travel.comingSoon")} />;
  }

  const groups = TRIP_TYPES.map((type) => ({ type, items: packages.filter((p) => p.trip_type === type) })).filter((g) => g.items.length > 0);
  const others = packages.filter((p) => !TRIP_TYPES.some((type) => type === p.trip_type));
  const agencies = Object.entries(
    packages.reduce<Record<string, number>>((acc, p) => {
      if (p.agency) acc[p.agency] = (acc[p.agency] ?? 0) + 1;
      return acc;
    }, {}),
  );

  return (
    <div className="flex flex-col gap-8">
      {groups.map(({ type, items }) => (
        <section key={type}>
          <SectionHeader
            title={t("travel.packagesOf", { type: t(`travel.types.${type}`) })}
            action={
              <ButtonLink href={`/${market.slug}/travel/search?trip_type=${type}`} variant="outline" size="sm">
                {t("travel.viewAll")}
              </ButtonLink>
            }
          />
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {items.slice(0, 4).map((pkg) => (
              <TripCard key={pkg.slug} pkg={pkg} market={market} />
            ))}
          </div>
        </section>
      ))}
      {others.length > 0 ? (
        <section>
          <SectionHeader title={t("travel.latest")} />
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {others.slice(0, 8).map((pkg) => (
              <TripCard key={pkg.slug} pkg={pkg} market={market} />
            ))}
          </div>
        </section>
      ) : null}
      {agencies.length > 0 ? (
        <section>
          <SectionHeader title={t("travel.agencies")} />
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {agencies.map(([name, count]) => (
              <div key={name} className="flex items-center gap-3 rounded-lg border border-line bg-surface p-4">
                <Building2 className="size-5 shrink-0 text-secondary" />
                <div className="min-w-0">
                  <p className="truncate text-body font-semibold">{name}</p>
                  <p className="text-caption text-secondary">{t("travel.agencyTrips", { count })}</p>
                </div>
              </div>
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}
