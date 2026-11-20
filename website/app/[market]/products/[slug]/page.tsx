import { ChevronRight, Home } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, ProductImage, Skeleton } from "@/components/ui/misc";
import { AdPlacements } from "@/features/page-builder/blocks/ads";
import { RatingBadge } from "@/components/ui/rating";
import { MerchBadge } from "@/components/product/product-card";
import { CompareToggle, CompareTray } from "@/features/compare/compare-store";
import { BuyBox } from "@/features/product/buy-box";
import { FrequentlyBoughtTogether } from "@/features/product/frequently-bought";
import { SelectedOfferProvider } from "@/features/product/offer-context";
import { OfferRail } from "@/features/product/offer-rail";
import { ProductGallery } from "@/features/product/gallery";
import { ProductQuestions } from "@/features/product/questions";
import { ProductReviews, ReviewsOverviewCard } from "@/features/product/reviews";
import { orNull } from "@/lib/api/client";
import { getProduct, getProductReviews, getProducts } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import { ProductTabs, TabLink } from "@/features/product/product-tabs";
import { RecentlyViewedRail, SimilarProductsRail } from "@/features/product/product-rails";
import type { ProductDetail, ReviewPage } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";

export async function generateMetadata({ params }: PageProps<"/[market]/products/[slug]">): Promise<Metadata> {
  const { market: slug, slug: productSlug } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const product = await orNull(getProduct(market.slug, productSlug).then((r) => r.data)).catch(() => null);
  if (!product) return {};
  const description = (product.description ?? textList(product.highlights).join(" · ")).replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
  const image = product.images[0]?.url;
  return {
    title: product.title,
    description: description || undefined,
    openGraph: { title: product.title, description: description || undefined, images: image ? [image] : undefined },
  };
}

/** Product detail (Figma 24:2; mobile 85:445; made to order 65:5397). */
export default function ProductPage({ params }: PageProps<"/[market]/products/[slug]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<PdpSkeleton />}>
        <ProductContent params={params} />
      </Suspense>
    </Container>
  );
}

async function ProductContent({ params }: { params: PageProps<"/[market]/products/[slug]">["params"] }) {
  const { market: slug, slug: productSlug } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const [product, reviews] = await Promise.all([
    orNull(getProduct(market.slug, productSlug).then((r) => r.data)),
    getProductReviews(market.slug, productSlug, { per_page: 10 })
      .then((r) => r.data)
      .catch(() => null),
  ]);
  if (!product) notFound();
  return <ProductView market={market} product={product} reviews={reviews} />;
}

function textList(values: unknown[]): string[] {
  return values.map((v) => (typeof v === "string" ? v : typeof v === "object" && v && "text" in v ? String((v as { text: unknown }).text) : "")).filter(Boolean);
}

function specRows(specs: ProductDetail["specifications"]): [string, string][] {
  if (Array.isArray(specs)) {
    return specs
      .map((row) => (row && typeof row === "object" ? [String((row as Record<string, unknown>).name ?? (row as Record<string, unknown>).label ?? ""), String((row as Record<string, unknown>).value ?? "")] : null))
      .filter((r): r is [string, string] => !!r && r[0] !== "");
  }
  return Object.entries(specs).map(([k, v]) => [k, typeof v === "object" ? JSON.stringify(v) : String(v)]);
}

function ProductView({ market, product, reviews }: { market: Market; product: ProductDetail; reviews: ReviewPage | null }) {
  const t = getT(market.locale);
  const highlights = textList(product.highlights);
  const specs = specRows(product.specifications);
  const crumbs = product.breadcrumbs.length > 0 ? product.breadcrumbs : product.category ? [{ slug: product.category.slug, name: product.category.name }] : [];
  // Written reviews when there are any, else the catalogue rating count (same number as under the title).
  const reviewCount = reviews && reviews.summary.count > 0 ? reviews.summary.count : (product.rating?.count ?? 0);

  return (
    <div className="flex flex-col gap-6">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        {crumbs.map((crumb, i) => (
          <span key={crumb.slug} className="contents">
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <Link href={`/${market.slug}/${i === 0 ? crumb.slug : `${crumbs[0].slug}/${crumb.slug}`}`} className="hover:text-ink">
              {crumb.name}
            </Link>
          </span>
        ))}
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <span className="line-clamp-1 text-ink">{product.title}</span>
      </nav>

      <SelectedOfferProvider initial={product.buy_box}>
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_300px] xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_320px]">
        <ProductGallery images={product.images} title={product.title} slug={product.slug} />

        <section className="flex flex-col gap-3">
          {product.brand ? (
            product.brand_slug ? (
              <Link href={`/${market.slug}/brands/${encodeURIComponent(product.brand_slug)}`} className="text-body font-medium text-link hover:underline">
                {product.brand}
              </Link>
            ) : (
              <p className="text-body font-medium text-link">{product.brand}</p>
            )
          ) : null}
          <h1 className="text-h2 font-semibold">{product.title}</h1>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-body-sm">
            {product.rating && product.rating.count > 0 ? (
              <a href="#reviews" className="flex items-center gap-2">
                <RatingBadge average={product.rating.average} count={product.rating.count} />
                <span className="text-link">{t("product.ratings", { count: product.rating.count })}</span>
              </a>
            ) : null}
            {product.model_number ? (
              <span className="text-secondary">
                {t("discovery.pdp.model")} <span dir="ltr">{product.model_number}</span>
              </span>
            ) : null}
          </div>
          {product.badges && product.badges.length > 0 ? (
            <ul className="flex flex-wrap gap-1.5" aria-label={t("discovery.pdp.badges")}>
              {product.badges.map((b) => (
                <li key={b.key}>
                  <MerchBadge badge={b} />
                </li>
              ))}
            </ul>
          ) : null}
          <div>
            <CompareToggle product={{ slug: product.slug, title: product.title, image: product.images[0]?.url ?? null }} />
          </div>
          <BuyBox product={product} />
        </section>

        <aside className="flex flex-col gap-3">
          <OfferRail madeToOrderDays={product.made_to_order ? product.lead_days : null} hasWarranty={product.warranty_plans.length > 0} />
          {/* Sponsored alternative (always labelled). */}
          <Suspense fallback={null}>
            <AdPlacements placement="product_page" ctx={{ market, categories: [] }} variant="tile" />
          </Suspense>
        </aside>
      </div>
      </SelectedOfferProvider>

      <FrequentlyBoughtTogether product={{ slug: product.slug, title: product.title, image: product.images[0]?.url ?? null, price: product.buy_box?.price ?? product.from_price }} />

      <ProductTabs
        tabs={[
          {
            id: "overview",
            label: t("product.tabOverview"),
            panel: (
              <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="flex flex-col gap-6 rounded-lg border border-line bg-surface p-5">
                  {highlights.length > 0 ? (
                    <div>
                      <h2 className="mb-3 text-h4 font-semibold">{t("product.highlights")}</h2>
                      <ul className="flex list-disc flex-col gap-2 ps-5 text-body marker:text-brand">
                        {highlights.map((h, i) => (
                          <li key={i}>{h}</li>
                        ))}
                      </ul>
                    </div>
                  ) : null}
                  {product.description ? (
                    <div>
                      <h2 className="mb-3 text-h4 font-semibold">{t("product.description")}</h2>
                      <div className="whitespace-pre-line text-body text-secondary">{product.description}</div>
                    </div>
                  ) : null}
                  {specs.length > 0 ? (
                    <div>
                      <h2 className="mb-3 text-h4 font-semibold">{t("product.specifications")}</h2>
                      <SpecTable specs={specs} />
                    </div>
                  ) : null}
                  {!product.description && highlights.length === 0 && specs.length === 0 ? <p className="text-body text-secondary">{product.title}</p> : null}
                </div>
                <ReviewsOverviewCard
                  slug={product.slug}
                  summary={reviews?.summary ?? null}
                  aggregate={product.rating}
                  seeAll={
                    <TabLink to="reviews" className="text-body-sm font-medium text-link hover:underline">
                      {t("product.seeAllReviews")}
                    </TabLink>
                  }
                />
              </div>
            ),
          },
          {
            id: "specifications",
            label: t("product.tabSpecs"),
            panel: (
              <div className="rounded-lg border border-line bg-surface p-5">
                <h2 className="mb-3 text-h4 font-semibold">{t("product.specifications")}</h2>
                {specs.length > 0 || product.model_number ? (
                  <SpecTable specs={product.model_number && !specs.some(([k]) => /model/i.test(k)) ? [[t("discovery.pdp.model").replace(/[:：]\s*$/, ""), product.model_number], ...specs] : specs} />
                ) : (
                  <p className="text-body text-secondary">{t("product.noSpecs")}</p>
                )}
              </div>
            ),
          },
          { id: "reviews", label: t("product.tabReviews", { count: new Intl.NumberFormat("en").format(reviewCount) }), panel: <ProductReviews slug={product.slug} initial={reviews} aggregate={product.rating} /> },
          { id: "questions", label: t("product.tabQuestions"), panel: <ProductQuestions slug={product.slug} /> },
        ]}
      />

      {product.category ? (
        <Suspense fallback={null}>
          <SimilarProducts market={market} category={product.category.slug} exclude={product.slug} title={t("product.similar")} />
        </Suspense>
      ) : null}
      <RecentlyViewedRail title={t("product.recentlyViewed")} exclude={product.slug} />
      <CompareTray />
    </div>
  );
}

function SpecTable({ specs }: { specs: [string, string][] }) {
  return (
    <div className="overflow-hidden rounded-md border border-line">
      <table className="w-full text-body-sm">
        <tbody>
          {specs.map(([k, v], i) => (
            <tr key={`${k}-${i}`} className={i % 2 === 0 ? "bg-subtle" : "bg-surface"}>
              <th scope="row" className="w-1/3 px-4 py-2.5 text-start font-normal text-secondary first-letter:uppercase">
                {k.replace(/_/g, " ")}
              </th>
              <td className="px-4 py-2.5">{v}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Same-category products for the "Similar products" carousel. */
async function SimilarProducts({ market, category, exclude, title }: { market: Market; category: string; exclude: string; title: string }) {
  const products = await getProducts(market.slug, { category, per_page: 16, sort: "recommended" })
    // Organic items only: the listing interleaves sponsored cards, which belong to ad slots.
    .then((r) => r.data.filter((p) => p.slug !== exclude && !p.sponsored).slice(0, 12).map(cardFromApi))
    .catch(() => []);
  return <SimilarProductsRail title={title} products={products} />;
}

function PdpSkeleton() {
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_1fr_300px]">
      <Skeleton className="aspect-square w-full" />
      <div className="flex flex-col gap-3">
        <Skeleton className="h-8 w-3/4" />
        <Skeleton className="h-10 w-1/3" />
        <Skeleton className="h-32 w-full" />
      </div>
      <Skeleton className="h-48 w-full" />
    </div>
  );
}
