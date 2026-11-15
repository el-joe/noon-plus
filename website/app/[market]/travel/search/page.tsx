import type { Metadata } from "next";
import { Plane } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Container, EmptyState, Skeleton } from "@/components/ui/misc";
import { TravelSearchForm, type TravelSearchValues } from "@/features/travel/search-form";
import { isTripType, TRIP_TYPES, TripCard } from "@/features/travel/shared";
import { getTravelPackages } from "@/lib/api/travel";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";
import type { MarketParams, SearchParams } from "@/features/travel/page-types";

const PER_PAGE = 24;

export async function generateMetadata({ params }: { params: MarketParams; searchParams: SearchParams }): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("travel.resultsTitle"), description: t("travel.metaDescription") };
}

/** `/travel/search` — trip results (destination, trip type, month). */
export default async function TravelSearchPage({ params, searchParams }: { params: MarketParams; searchParams: SearchParams }) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => {
          const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v)?.trim() || undefined;
          const month = one(raw.month);
          const page = Math.max(1, Number(one(raw.page)) || 1);
          const values: TravelSearchValues = {
            destination: one(raw.destination)?.slice(0, 80),
            trip_type: isTripType(one(raw.trip_type)) ? one(raw.trip_type) : undefined,
            month: month && /^\d{4}-\d{2}$/.test(month) ? month : undefined,
          };
          return <Results market={market} values={values} page={page} />;
        })}
      </Suspense>
    </Container>
  );
}

function query(values: TravelSearchValues, extra: Record<string, string | number | undefined> = {}): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries({ ...values, ...extra })) if (v !== undefined && v !== "") params.set(k, String(v));
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

async function Results({ market, values, page }: { market: Market; values: TravelSearchValues; page: number }) {
  const t = getT(market.locale);
  const base = `/${market.slug}/travel/search`;
  const result = await getTravelPackages(market.slug, { ...values, page }).catch(() => null);
  const packages = result?.data ?? [];
  const total = Number(result?.meta.total ?? packages.length);
  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
  const filtered = !!(values.destination || values.trip_type || values.month);

  return (
    <div className="flex flex-col gap-4">
      <nav className="text-body-sm text-secondary">
        <Link href={`/${market.slug}/travel`} className="hover:text-ink">
          {t("travel.title")}
        </Link>
      </nav>
      <TravelSearchForm market={market} values={values} className="border border-line" />
      <div className="scrollbar-none flex gap-2 overflow-x-auto">
        <Link href={`${base}${query({ ...values, trip_type: undefined })}`} className={`shrink-0 rounded-full px-3 py-1 text-body-sm ${!values.trip_type ? "bg-ink font-medium text-white" : "border border-line bg-surface"}`}>
          {t("travel.types.all")}
        </Link>
        {TRIP_TYPES.map((type) => (
          <Link key={type} href={`${base}${query({ ...values, trip_type: type })}`} className={`shrink-0 rounded-full px-3 py-1 text-body-sm ${values.trip_type === type ? "bg-ink font-medium text-white" : "border border-line bg-surface"}`}>
            {t(`travel.types.${type}`)}
          </Link>
        ))}
      </div>
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">
          {values.trip_type && isTripType(values.trip_type) ? t("travel.packagesOf", { type: t(`travel.types.${values.trip_type}`) }) : t("travel.resultsTitle")}
        </h1>
        <span className="text-body-sm text-secondary">{t("travel.resultsCount", { count: total })}</span>
      </div>
      {packages.length === 0 ? (
        <EmptyState
          icon={<Plane className="size-10" />}
          title={t("travel.empty")}
          body={t("travel.emptyBody")}
          action={filtered ? <ButtonLink href={base} variant="outline">{t("travel.clearFilters")}</ButtonLink> : undefined}
        />
      ) : (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {packages.map((pkg) => (
            <TripCard key={pkg.slug} pkg={pkg} market={market} />
          ))}
        </div>
      )}
      {lastPage > 1 ? (
        <div className="flex items-center justify-center gap-3">
          {page > 1 ? <ButtonLink href={`${base}${query(values, { page: page - 1 })}`} variant="outline" size="sm">{t("travel.previous")}</ButtonLink> : null}
          <span className="text-body-sm text-secondary">
            {page} / {lastPage}
          </span>
          {page < lastPage ? <ButtonLink href={`${base}${query(values, { page: page + 1 })}`} variant="outline" size="sm">{t("travel.next")}</ButtonLink> : null}
        </div>
      ) : null}
    </div>
  );
}
