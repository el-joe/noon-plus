import type { Metadata } from "next";
import { Suspense } from "react";
import { ButtonLink } from "@/components/ui/button";
import { Container, Skeleton } from "@/components/ui/misc";
import { ProductGrid } from "@/components/product/product-grid";
import { OffersForYou } from "@/features/offers/offers-for-you";
import { loadCmsPage } from "@/features/page-builder/cms-page";
import { PageZone } from "@/features/page-builder/render";
import { orNull } from "@/lib/api/client";
import { getPage, getProducts } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import type { Category } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";
import Link from "next/link";

/** SEO title / description from the published `home` page (`pages/home` seo); the layout default otherwise. */
export async function generateMetadata({ params }: PageProps<"/[market]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const page = await orNull(getPage(market.slug, "home").then((r) => r.data)).catch(() => null);
  const title = page?.seo?.title;
  const description = page?.seo?.description;
  return {
    ...(title ? { title: { absolute: title } } : {}),
    ...(description ? { description } : {}),
    ...(title || description ? { openGraph: { title: title ?? undefined, description: description ?? undefined } } : {}),
  };
}

/** Home (Figma "Home v2 desktop" 30:1208) — rendered from the `home` page-builder page. */
export default async function HomePage({ params }: PageProps<"/[market]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<HomeSkeleton />}>
        <Home market={market} />
      </Suspense>
    </Container>
  );
}

async function Home({ market }: { market: Market }) {
  const { page, categories } = await loadCmsPage(market, "home");
  const ctx = { market, categories };
  if (page && (page.zones.main?.length ?? 0) > 0) {
    return (
      <div className="flex flex-col gap-6">
        <PageZone blocks={page.zones.main} ctx={ctx} />
        <OffersForYou />
      </div>
    );
  }
  return <HomeFallback market={market} categories={categories} />;
}

/** Shown until an admin publishes the `home` page for this country and language. */
async function HomeFallback({ market, categories }: { market: Market; categories: Category[] }) {
  const t = getT(market.locale);
  const latest = await getProducts(market.slug, { sort: "newest", per_page: 12 })
    .then((r) => r.data)
    .catch(() => []);
  return (
    <div className="flex flex-col gap-8">
      <section className="grid items-center gap-6 overflow-hidden rounded-lg bg-brand p-6 md:grid-cols-[1fr_auto] md:p-10">
        <div>
          <h1 className="text-h1 font-extrabold">{t("home.heroTitle")}</h1>
          <p className="mt-2 max-w-xl text-body-lg">{t("home.heroSubtitle")}</p>
          <ButtonLink href={`/${market.slug}/search?q=`} variant="dark" className="mt-5" prefetch={false}>
            {t("header.search")}
          </ButtonLink>
        </div>
        <div className="hidden gap-3 md:flex">
          {["product-smartwatch", "product-earbuds", "product-perfume"].map((img) => (
            // eslint-disable-next-line @next/next/no-img-element
            <img key={img} src={`/img/${img}.png`} alt="" className="size-32 rounded-lg bg-surface object-contain" />
          ))}
        </div>
      </section>
      {categories.length > 0 ? (
        <section>
          <h2 className="mb-3 text-h3 font-semibold">{t("home.shopByCategory")}</h2>
          <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            {categories.map((c) => (
              <Link key={c.slug} href={`/${market.slug}/${c.slug}`} className="flex flex-col items-center gap-2 rounded-lg bg-surface p-3 text-center hover:shadow-sm">
                <span className="flex size-14 items-center justify-center rounded-full bg-brand-subtle text-h3 font-bold text-brand-text">{c.name.slice(0, 1)}</span>
                <span className="text-body-sm font-medium">{c.name}</span>
              </Link>
            ))}
          </div>
        </section>
      ) : null}
      <OffersForYou />
      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("home.newArrivals")}</h2>
        {latest.length > 0 ? <ProductGrid products={latest.map(cardFromApi)} columns={6} /> : <p className="rounded-lg bg-surface p-6 text-body text-secondary">{t("home.emptyCatalog")}</p>}
      </section>
    </div>
  );
}

function HomeSkeleton() {
  return (
    <div className="flex flex-col gap-6">
      <Skeleton className="h-[300px] w-full" />
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-72 w-full" />
    </div>
  );
}
