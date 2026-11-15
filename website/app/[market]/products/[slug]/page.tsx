import { ChevronRight, Home, RotateCcw, ShieldCheck } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, ProductImage, Skeleton } from "@/components/ui/misc";
import { AdPlacements } from "@/features/page-builder/blocks/ads";
import { RatingBadge } from "@/components/ui/rating";
import { BuyBox } from "@/features/product/buy-box";
import { ProductGallery } from "@/features/product/gallery";
import { ProductQuestions } from "@/features/product/questions";
import { ProductReviews } from "@/features/product/reviews";
import { orNull } from "@/lib/api/client";
import { getProduct, getProductReviews } from "@/lib/api/endpoints";
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
  const reviewCount = reviews?.summary.count ?? product.rating?.count ?? 0;

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

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_300px]">
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
          {product.rating && product.rating.count > 0 ? (
            <a href="#reviews" className="flex items-center gap-2 text-body-sm">
              <RatingBadge average={product.rating.average} count={product.rating.count} />
              <span className="text-link">{t("product.ratings", { count: product.rating.count })}</span>
            </a>
          ) : null}
          <BuyBox product={product} />
        </section>

        <aside className="flex flex-col gap-3">
          <div className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4">
            <p className="flex items-start gap-2 text-body-sm">
              <RotateCcw className="mt-0.5 size-4 shrink-0" />
              <span>
                <span className="block font-medium">{t("product.freeReturns")}</span>
                <span className="text-secondary">{t("product.freeReturnsHint")}</span>
              </span>
            </p>
            <p className="flex items-start gap-2 text-body-sm">
              <ShieldCheck className="mt-0.5 size-4 shrink-0" />
              <span>
                <span className="block font-medium">{t("product.securePayments")}</span>
                <span className="text-secondary">{t("product.securePaymentsHint")}</span>
              </span>
            </p>
          </div>
          {/* Sponsored alternative (always labelled). */}
          <Suspense fallback={null}>
            <AdPlacements placement="product_page" ctx={{ market, categories: [] }} variant="tile" />
          </Suspense>
        </aside>
      </div>

      <nav className="scrollbar-none -mx-4 flex gap-6 overflow-x-auto border-b border-line bg-page px-4 text-body md:sticky md:top-[116px] md:z-10" aria-label={t("product.tabOverview")}>
        <a href="#overview" className="shrink-0 border-b-2 border-brand py-3 font-semibold">
          {t("product.tabOverview")}
        </a>
        {specs.length > 0 ? (
          <a href="#specifications" className="shrink-0 py-3 text-secondary hover:text-ink">
            {t("product.tabSpecs")}
          </a>
        ) : null}
        <a href="#reviews" className="shrink-0 py-3 text-secondary hover:text-ink">
          {t("product.tabReviews", { count: reviewCount })}
        </a>
        <a href="#questions" className="shrink-0 py-3 text-secondary hover:text-ink">
          {t("product.tabQuestions")}
        </a>
      </nav>

      <section id="overview" className="flex scroll-mt-32 flex-col gap-6 rounded-lg border border-line bg-surface p-5">
        {product.description ? (
          <div>
            <h2 className="mb-3 text-h4 font-semibold">{t("product.description")}</h2>
            <div className="whitespace-pre-line text-body text-secondary">{product.description}</div>
          </div>
        ) : null}
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
        {specs.length > 0 ? (
          <div id="specifications" className="scroll-mt-32">
            <h2 className="mb-3 text-h4 font-semibold">{t("product.specifications")}</h2>
            <table className="w-full overflow-hidden rounded-md border border-line text-body-sm">
              <tbody>
                {specs.map(([k, v], i) => (
                  <tr key={`${k}-${i}`} className={i % 2 === 0 ? "bg-subtle" : "bg-surface"}>
                    <th className="w-1/3 px-3 py-2 text-start font-normal text-secondary first-letter:uppercase">{k.replace(/_/g, " ")}</th>
                    <td className="px-3 py-2">{v}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {!product.description && highlights.length === 0 && specs.length === 0 ? <p className="text-body text-secondary">{product.title}</p> : null}
      </section>

      <ProductReviews slug={product.slug} initial={reviews} />
      <ProductQuestions slug={product.slug} />
    </div>
  );
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
