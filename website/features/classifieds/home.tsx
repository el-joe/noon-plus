import { Car, Home as HomeIcon, LayoutGrid, Plus, Search, Smartphone, Sofa, Tag } from "lucide-react";
import Link from "next/link";
import { ButtonLink, buttonClass } from "@/components/ui/button";
import { EmptyState, SectionHeader } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdCard as AdCardData, ClassifiedCategory } from "@/lib/api/classifieds";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { AdGrid } from "./ad-card";
import { loadClassifiedCategories } from "./data";

const ICONS: [RegExp, typeof Car][] = [
  [/car|motor|vehicle|auto/i, Car],
  [/propert|real|home|apart|villa|rent/i, HomeIcon],
  [/mobile|phone|electr|tablet/i, Smartphone],
  [/furnit|garden|home/i, Sofa],
];

function iconFor(category: ClassifiedCategory) {
  return ICONS.find(([re]) => re.test(category.slug))?.[1] ?? Tag;
}

const fetchAds = (market: Market, filters: api.AdFilters) =>
  api
    .getAds(market.slug, { per_page: 10, ...filters })
    .then((r) => r.data)
    .catch(() => [] as AdCardData[]);

/** `/classified` — Open Souq home (Figma Classifieds home 54:2). */
export async function ClassifiedsHome({ market }: { market: Market }) {
  const t = getT(market.locale);
  const base = `/${market.slug}/classified`;
  const [{ tree, propertySlugs }, featured, newest, wanted] = await Promise.all([
    loadClassifiedCategories(market.slug),
    fetchAds(market, { sort: "recommended" }),
    fetchAds(market, { sort: "newest" }),
    fetchAds(market, { kind: "wanted", sort: "newest", per_page: 5 }),
  ]);
  const sections = await Promise.all(
    tree.slice(0, 3).map(async (category) => ({ category, ads: await fetchAds(market, { category: category.slug, sort: "newest", per_page: 5 }) })),
  );

  return (
    <div className="flex flex-col gap-8">
      <section className="rounded-xl bg-ink px-5 py-8 text-white md:px-10 md:py-10">
        <h1 className="text-h1 font-bold">{t("classifieds.home.title")}</h1>
        <p className="mt-2 text-body opacity-80">{t("classifieds.home.subtitle")}</p>
        <form action={`${base}/search`} className="mt-5 flex flex-col gap-2 rounded-lg bg-surface p-2 text-ink md:flex-row">
          <select name="category" className="h-11 rounded-md border border-line bg-surface px-3 text-body md:w-56" defaultValue="">
            <option value="">{t("classifieds.allCategories")}</option>
            {tree.map((c) => (
              <option key={c.slug} value={c.slug}>
                {c.name}
              </option>
            ))}
          </select>
          <input name="q" type="search" placeholder={t("classifieds.searchPlaceholder")} className="h-11 flex-1 rounded-md border border-line px-3 text-body" />
          <input name="city" placeholder={t("classifieds.cityPlaceholder")} className="h-11 rounded-md border border-line px-3 text-body md:w-44" />
          <button type="submit" className={buttonClass("primary", "lg")}>
            <Search className="size-4" />
            {t("classifieds.search")}
          </button>
        </form>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <ButtonLink href={`${base}/post`} variant="primary">
            <Plus className="size-4" />
            {t("classifieds.postAdFree")}
          </ButtonLink>
          <span className="text-body-sm opacity-80">{t("classifieds.home.postHint")}</span>
        </div>
      </section>

      <section>
        <SectionHeader title={t("classifieds.home.browse")} />
        {tree.length === 0 ? (
          <EmptyState icon={<LayoutGrid className="size-10" />} title={t("classifieds.home.noCategories")} />
        ) : (
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            {tree.map((category) => {
              const Icon = iconFor(category);
              const href = propertySlugs.includes(category.slug) ? `/${market.slug}/property?category=${category.slug}` : `${base}/search?category=${category.slug}`;
              return (
                <Link key={category.slug} href={href} className="flex flex-col items-center gap-2 rounded-lg border border-line bg-surface p-3 text-center hover:shadow-sm">
                  <span className="flex size-10 items-center justify-center rounded-full bg-brand-subtle text-brand-text">
                    <Icon className="size-5" />
                  </span>
                  <span className="line-clamp-2 text-body-sm font-medium">{category.name}</span>
                </Link>
              );
            })}
            <Link href={`${base}/search?kind=wanted`} className="flex flex-col items-center gap-2 rounded-lg border border-line bg-surface p-3 text-center hover:shadow-sm">
              <span className="flex size-10 items-center justify-center rounded-full bg-brand-subtle text-brand-text">
                <Search className="size-5" />
              </span>
              <span className="text-body-sm font-medium">{t("classifieds.wanted")}</span>
            </Link>
          </div>
        )}
      </section>

      <AdSection title={t("classifieds.home.featured")} href={`${base}/search?sort=recommended`} ads={featured} propertySlugs={propertySlugs} market={market} />
      <AdSection title={t("classifieds.home.newToday")} href={`${base}/search?sort=newest`} ads={newest} propertySlugs={propertySlugs} market={market} />

      <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-brand p-6">
        <div>
          <p className="text-h3 font-bold">{t("classifieds.home.featureTitle")}</p>
          <p className="text-body">{t("classifieds.home.featureBody")}</p>
        </div>
        <ButtonLink href={`/${market.slug}/account/my-ads`} variant="dark">
          {t("classifieds.home.featureCta")}
        </ButtonLink>
      </section>

      {sections.map(({ category, ads }) => (
        <AdSection key={category.slug} title={category.name} href={`${base}/search?category=${category.slug}`} ads={ads} propertySlugs={propertySlugs} market={market} />
      ))}
      <AdSection title={t("classifieds.home.wantedAds")} href={`${base}/search?kind=wanted`} ads={wanted} propertySlugs={propertySlugs} market={market} />

      {featured.length === 0 && newest.length === 0 ? (
        <EmptyState
          title={t("classifieds.home.emptyTitle")}
          body={t("classifieds.home.emptyBody")}
          action={<ButtonLink href={`${base}/post`}>{t("classifieds.postAd")}</ButtonLink>}
        />
      ) : null}
    </div>
  );
}

function AdSection({ title, href, ads, propertySlugs, market }: { title: string; href: string; ads: AdCardData[]; propertySlugs: string[]; market: Market }) {
  if (ads.length === 0) return null;
  const t = getT(market.locale);
  return (
    <section>
      <SectionHeader
        title={title}
        action={
          <Link href={href} className="text-body-sm font-semibold text-link">
            {t("common.viewAll")}
          </Link>
        }
      />
      <AdGrid ads={ads.slice(0, 10)} propertySlugs={propertySlugs} />
    </section>
  );
}
