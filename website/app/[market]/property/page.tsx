import type { Metadata } from "next";
import { Building2, MapPinned } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { buttonClass, ButtonLink } from "@/components/ui/button";
import { inputClass } from "@/components/ui/field";
import { Container, EmptyState, Skeleton } from "@/components/ui/misc";
import { propertyTypeLabel, PropertyCardView } from "@/features/real-estate/shared";
import type { MarketParams, SearchParams } from "@/features/travel/page-types";
import { getClassifiedCategories, getProperties, PROPERTY_SORTS, PROPERTY_TYPES, propertyCategories, PURPOSES, type PropertyCategory, type PropertyFilters } from "@/lib/api/real-estate";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

const PER_PAGE = 24;
const KEYS = ["category", "q", "city", "purpose", "property_type", "bedrooms_min", "bathrooms_min", "min_area", "max_area", "min_price", "max_price", "sort"] as const;

export async function generateMetadata({ params }: { params: MarketParams }): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("realEstate.title"), description: t("realEstate.metaDescription") };
}

/** `/property` — real estate results (Figma 70:6418): property-vertical classified ads with purpose / type / beds / price filters. */
export default async function PropertyResultsPage({ params, searchParams }: { params: MarketParams; searchParams: SearchParams }) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => {
          const filters: PropertyFilters = {};
          for (const key of KEYS) {
            const v = raw[key];
            const value = (Array.isArray(v) ? v[0] : v)?.trim();
            if (value) filters[key] = value.slice(0, 120);
          }
          if (filters.purpose && !(PURPOSES as readonly string[]).includes(filters.purpose)) delete filters.purpose;
          if (filters.sort && !(PROPERTY_SORTS as readonly string[]).includes(filters.sort)) delete filters.sort;
          for (const k of ["bedrooms_min", "bathrooms_min", "min_area", "max_area", "min_price", "max_price"] as const) if (filters[k] && !/^\d+(\.\d+)?$/.test(filters[k]!)) delete filters[k];
          const page = Math.max(1, Number(Array.isArray(raw.page) ? raw.page[0] : raw.page) || 1);
          return <Results market={market} filters={filters} page={page} />;
        })}
      </Suspense>
    </Container>
  );
}

function qs(filters: PropertyFilters, extra: Record<string, string | number | undefined> = {}): string {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries({ ...filters, ...extra })) if (v !== undefined && v !== "" && k !== "per_page") p.set(k, String(v));
  const s = p.toString();
  return s ? `?${s}` : "";
}

async function Results({ market, filters, page }: { market: Market; filters: PropertyFilters; page: number }) {
  const t = getT(market.locale);
  const base = `/${market.slug}/property`;
  const tree = await getClassifiedCategories(market.slug)
    .then((r) => r.data)
    .catch(() => [] as PropertyCategory[]);
  const roots = propertyCategories(tree);
  const all = roots.flatMap(function flat(c): PropertyCategory[] {
    return [c, ...c.children.flatMap(flat)];
  });
  const current = all.find((c) => c.slug === filters.category) ?? roots[0] ?? null;

  if (!current) {
    return <EmptyState icon={<Building2 className="size-10" />} title={t("realEstate.title")} body={t("realEstate.comingSoon")} />;
  }

  const result = await getProperties(market.slug, { ...filters, category: current.slug, page, per_page: PER_PAGE }).catch(() => null);
  const ads = result?.data ?? [];
  const total = Number(result?.meta.total ?? ads.length);
  const lastPage = Number(result?.meta.last_page ?? Math.max(1, Math.ceil(total / PER_PAGE)));
  const filtered = KEYS.some((k) => k !== "category" && k !== "sort" && filters[k]);
  const chips = roots.length > 1 ? roots : current.children.length > 0 ? [current, ...current.children] : roots;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-body-sm text-secondary">{t("realEstate.title")}</p>
          <h1 className="text-h2 font-bold">{filters.category ? current.name : t("realEstate.resultsTitle")}</h1>
        </div>
        <span className="text-body-sm text-secondary">{t("realEstate.resultsCount", { count: total })}</span>
      </div>

      {chips.length > 1 ? (
        <div className="scrollbar-none flex gap-2 overflow-x-auto">
          {chips.map((c) => (
            <Link key={c.slug} href={`${base}${qs({ ...filters, category: c.slug })}`} className={`shrink-0 rounded-full px-3 py-1 text-body-sm ${c.slug === current.slug ? "bg-ink font-medium text-white" : "border border-line bg-surface"}`}>
              {c.name}
            </Link>
          ))}
        </div>
      ) : null}

      <form action={base} method="get" className="grid gap-3 rounded-lg border border-line bg-surface p-3 sm:grid-cols-2 lg:grid-cols-[repeat(6,minmax(0,1fr))_auto] lg:items-end">
        {filters.category ? <input type="hidden" name="category" value={filters.category} /> : null}
        <Labeled label={t("realEstate.purpose.any")}>
          <select name="purpose" defaultValue={filters.purpose ?? ""} className={`${inputClass} pe-8`}>
            <option value="">{t("realEstate.purpose.any")}</option>
            {PURPOSES.map((p) => (
              <option key={p} value={p}>
                {t(`realEstate.purpose.${p}`)}
              </option>
            ))}
          </select>
        </Labeled>
        <Labeled label={t("realEstate.propertyType")}>
          <select name="property_type" defaultValue={filters.property_type ?? ""} className={`${inputClass} pe-8`}>
            <option value="">{t("realEstate.anyType")}</option>
            {PROPERTY_TYPES.map((p) => (
              <option key={p} value={p}>
                {propertyTypeLabel(t, p)}
              </option>
            ))}
          </select>
        </Labeled>
        <Labeled label={t("realEstate.city")}>
          <input name="city" defaultValue={filters.city ?? ""} maxLength={80} placeholder={t("realEstate.cityPlaceholder")} className={inputClass} />
        </Labeled>
        <Labeled label={t("realEstate.bedrooms")}>
          <select name="bedrooms_min" defaultValue={filters.bedrooms_min ?? ""} className={`${inputClass} pe-8`}>
            <option value="">{t("realEstate.any")}</option>
            {[1, 2, 3, 4, 5].map((n) => (
              <option key={n} value={n}>
                {t("realEstate.bedroomsMin", { count: n })}
              </option>
            ))}
          </select>
        </Labeled>
        <Labeled label={t("realEstate.bathrooms")}>
          <select name="bathrooms_min" defaultValue={filters.bathrooms_min ?? ""} className={`${inputClass} pe-8`}>
            <option value="">{t("realEstate.any")}</option>
            {[1, 2, 3, 4].map((n) => (
              <option key={n} value={n}>
                {t("realEstate.bathroomsMin", { count: n })}
              </option>
            ))}
          </select>
        </Labeled>
        <Labeled label={t("realEstate.minArea")}>
          <input name="min_area" type="number" min={0} inputMode="numeric" defaultValue={filters.min_area ?? ""} className={inputClass} />
        </Labeled>
        <Labeled label={t("realEstate.maxArea")}>
          <input name="max_area" type="number" min={0} inputMode="numeric" defaultValue={filters.max_area ?? ""} className={inputClass} />
        </Labeled>
        <Labeled label={t("realEstate.minPrice")}>
          <input name="min_price" type="number" min={0} inputMode="numeric" defaultValue={filters.min_price ?? ""} className={inputClass} />
        </Labeled>
        <Labeled label={t("realEstate.maxPrice")}>
          <input name="max_price" type="number" min={0} inputMode="numeric" defaultValue={filters.max_price ?? ""} className={inputClass} />
        </Labeled>
        <input type="hidden" name="sort" value={filters.sort ?? ""} />
        <button type="submit" className={buttonClass("primary", "md", "h-11")}>
          {t("realEstate.search")}
        </button>
      </form>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2 text-body-sm">
          <span className="text-secondary">{t("realEstate.sortLabel")}:</span>
          {PROPERTY_SORTS.map((s) => (
            <Link key={s} href={`${base}${qs({ ...filters, sort: s })}`} className={(filters.sort ?? "recommended") === s ? "font-semibold text-ink" : "text-link hover:underline"}>
              {t(`realEstate.sort.${s}`)}
            </Link>
          ))}
        </div>
        <span className="inline-flex items-center gap-1 text-caption text-secondary">
          <MapPinned className="size-4" />
          {t("realEstate.mapSoon")}
        </span>
      </div>

      {ads.length === 0 ? (
        <EmptyState
          icon={<Building2 className="size-10" />}
          title={t("realEstate.empty")}
          body={t("realEstate.emptyBody")}
          action={
            <div className="flex flex-wrap justify-center gap-2">
              {filtered ? (
                <ButtonLink href={base} variant="outline">
                  {t("realEstate.clearFilters")}
                </ButtonLink>
              ) : null}
              <ButtonLink href={`/${market.slug}/account/special-requests`}>{t("realEstate.postRequest")}</ButtonLink>
            </div>
          }
        />
      ) : (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {ads.map((ad) => (
            <PropertyCardView key={ad.number} ad={ad} market={market} />
          ))}
        </div>
      )}

      {lastPage > 1 ? (
        <div className="flex items-center justify-center gap-3">
          {page > 1 ? <ButtonLink href={`${base}${qs(filters, { page: page - 1 })}`} variant="outline" size="sm">{t("realEstate.previous")}</ButtonLink> : null}
          <span className="text-body-sm text-secondary">
            {page} / {lastPage}
          </span>
          {page < lastPage ? <ButtonLink href={`${base}${qs(filters, { page: page + 1 })}`} variant="outline" size="sm">{t("realEstate.next")}</ButtonLink> : null}
        </div>
      ) : null}

      {ads.length > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-subtle p-4">
          <p className="text-body">{t("realEstate.special.intro")}</p>
          <ButtonLink href={`/${market.slug}/account/special-requests`} variant="dark" size="sm">
            {t("realEstate.postRequest")}
          </ButtonLink>
        </div>
      ) : null}
    </div>
  );
}

function Labeled({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-caption font-medium text-secondary">{label}</span>
      {children}
    </label>
  );
}
