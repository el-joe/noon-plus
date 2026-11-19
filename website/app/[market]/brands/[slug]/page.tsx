import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ProductCard } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { Container, Skeleton } from "@/components/ui/misc";
import { Crumbs } from "@/features/content/pagination";
import { ProductListing, toListingQuery, type ListingQuery } from "@/features/listing/product-listing";
import { loadCmsPage } from "@/features/page-builder/cms-page";
import { PageZone } from "@/features/page-builder/render";
import type { BlockContext } from "@/features/page-builder/settings";
import { orNull } from "@/lib/api/client";
import { getBrand } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import type { BrandDetail } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";
import { withoutBrandSuffix } from "@/lib/format";

const SLUG = /^[A-Za-z0-9-]{1,120}$/;

async function load(params: PageProps<"/[market]/brands/[slug]">["params"]): Promise<{ market: Market; brand: BrandDetail | null }> {
  const { market: m, slug } = await params;
  const market = parseMarket(m);
  const key = decodeURIComponent(slug);
  if (!market || !SLUG.test(key)) notFound();
  const brand = await orNull(getBrand(market.slug, key).then((r) => r.data));
  return { market, brand };
}

export async function generateMetadata({ params }: PageProps<"/[market]/brands/[slug]">): Promise<Metadata> {
  const { market, brand } = await load(params).catch(() => ({ market: null, brand: null }));
  if (!market) return {};
  const t = getT(market.locale);
  if (!brand) return { title: t("brand.notFound") };
  const cms = brand.page ? (await loadCmsPage(market, brand.page)).page : null;
  const title = withoutBrandSuffix(cms?.seo?.title) || brand.name;
  const description = cms?.seo?.description || t("brand.metaDescription", { brand: brand.name, count: brand.product_count });
  return { title, description, openGraph: { title, description, images: brand.logo ? [brand.logo] : undefined } };
}

/**
 * `/brands/{slug}` — brand store (`GET brands/{slug}`). When the brand has a
 * published page-builder page (`page`), that page is rendered (brand store
 * template); otherwise the brand header, top products and the brand listing.
 */
export default function BrandPage(props: PageProps<"/[market]/brands/[slug]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <BrandContent {...props} />
      </Suspense>
    </Container>
  );
}

async function BrandContent({ params, searchParams }: PageProps<"/[market]/brands/[slug]">) {
  const { market, brand } = await load(params);
  if (!brand) notFound();
  const raw = await searchParams;
  const query: ListingQuery = { ...toListingQuery(raw), brand: brand.slug };
  const category = typeof raw.category === "string" && SLUG.test(raw.category) ? raw.category : null;
  const basePath = `/${market.slug}/brands/${encodeURIComponent(brand.slug)}`;
  const t = getT(market.locale);

  if (brand.page) {
    const { page, categories } = await loadCmsPage(market, brand.page);
    if (page) {
      const ctx: BlockContext = { market, categories, category: null, listing: { query, basePath } };
      const zones = page.zones;
      return (
        <div className="flex flex-col gap-6">
          <PageZone blocks={zones.header} ctx={ctx} />
          <PageZone blocks={zones.top} ctx={ctx} />
          <PageZone blocks={zones.main} ctx={ctx} />
          {(zones.listing?.length ?? 0) > 0 ? <PageZone blocks={zones.listing} ctx={ctx} /> : <ProductListing market={market} query={query} basePath={basePath} category={category} />}
          <PageZone blocks={zones.bottom} ctx={ctx} />
        </div>
      );
    }
  }

  const top = brand.top_products.map(cardFromApi);
  return (
    <div className="flex flex-col gap-5">
      <Crumbs market={market} items={[{ label: brand.name }]} />
      <header className="flex flex-wrap items-center gap-4 rounded-lg border border-line bg-surface p-5">
        <span className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-line bg-white text-h3 font-bold">
          {brand.logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={brand.logo} alt={brand.name} className="size-full object-contain p-2" />
          ) : (
            brand.name.slice(0, 2).toUpperCase()
          )}
        </span>
        <div className="min-w-0 flex-1">
          <h1 className="text-h1 font-bold">{brand.name}</h1>
          <p className="text-body-sm text-secondary">{t("brand.products", { count: brand.product_count })}</p>
        </div>
      </header>

      {brand.categories.length > 0 ? (
        <nav className="scrollbar-none flex gap-2 overflow-x-auto" aria-label={brand.name}>
          <Link href={basePath} className={chip(!category)}>
            {t("brand.allCategories")}
          </Link>
          {brand.categories.map((c) => (
            <Link key={c.slug} href={`${basePath}?category=${encodeURIComponent(c.slug)}`} className={chip(category === c.slug)}>
              {c.name} <span className="opacity-70">{c.count}</span>
            </Link>
          ))}
        </nav>
      ) : null}

      {top.length > 0 && !category ? (
        <section>
          <h2 className="mb-3 text-h3 font-semibold">{t("brand.topProducts")}</h2>
          <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
            {top.map((p, i) => (
              <ProductCard key={`${p.slug}-${i}`} product={p} />
            ))}
          </Rail>
        </section>
      ) : null}

      <section className="flex flex-col gap-3">
        <h2 className="text-h3 font-semibold">{t("brand.allProducts", { brand: brand.name })}</h2>
        <ProductListing market={market} query={query} basePath={category ? `${basePath}?category=${encodeURIComponent(category)}` : basePath} category={category} />
      </section>
    </div>
  );
}

function chip(active: boolean): string {
  return active ? "shrink-0 rounded-full border border-ink bg-ink px-3 py-1.5 text-body-sm font-medium text-white" : "shrink-0 rounded-full border border-line bg-surface px-3 py-1.5 text-body-sm font-medium hover:bg-subtle";
}
