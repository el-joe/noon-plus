import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { categoryTree, categoryTreeWithCounts, ProductListing, toListingQuery, type TreeNode } from "@/features/listing/product-listing";
import { CategoryHeader, SubcategoryChips, SubcategoryTiles } from "@/features/page-builder/blocks/category";
import { loadCmsPage } from "@/features/page-builder/cms-page";
import { PageZone } from "@/features/page-builder/render";
import type { BlockContext } from "@/features/page-builder/settings";
import { orNull } from "@/lib/api/client";
import { getCategory, getCategoryTree, getPage } from "@/lib/api/endpoints";
import type { Category, CategoryNode } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { parseMarket } from "@/lib/markets";
import { withoutBrandSuffix } from "@/lib/format";

const SEGMENT = /^[a-z0-9-]+$/;

/** SEO from the page-builder page (`pages/{slug}` seo), else the category name. */
export async function generateMetadata({ params }: PageProps<"/[market]/[...slug]">): Promise<Metadata> {
  const { market: slug, slug: segments } = await params;
  const market = parseMarket(slug);
  const last = segments.at(-1);
  if (!market || !last || !SEGMENT.test(last)) return {};
  const [page, category] = await Promise.all([
    orNull(getPage(market.slug, last).then((r) => r.data)).catch(() => null),
    orNull(getCategory(market.slug, last).then((r) => r.data)).catch(() => null),
  ]);
  const title = withoutBrandSuffix(page?.seo?.title || page?.title || category?.name);
  const description = page?.seo?.description || (category ? getT(market.locale)("listing.products", { count: category.product_count }) : undefined);
  return title ? { title, description: description ?? undefined, openGraph: { title, description: description ?? undefined } } : {};
}

/**
 * `/{market}/{category}[/{sub}…]` — category PLP (Figma Category v2 40:4438,
 * with sub-categories 47:4471). A published page-builder page with the same
 * slug wins (store landing / campaign / brand store templates, 47:5448 …).
 * The last segment is resolved with `categories/{slug}` (any depth), the
 * sidebar uses `categories/tree` counts.
 */
export default function CatchAllPage({ params, searchParams }: PageProps<"/[market]/[...slug]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <CategoryContent params={params} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}

async function CategoryContent({ params, searchParams }: Pick<PageProps<"/[market]/[...slug]">, "params" | "searchParams">) {
  const { market: slug, slug: segments } = await params;
  const market = parseMarket(slug);
  if (!market || segments.length > 4 || segments.some((p) => !SEGMENT.test(p))) notFound();

  const query = toListingQuery(await searchParams);
  const last = segments.at(-1)!;
  const [{ page, categories }, detail, nodes] = await Promise.all([
    loadCmsPage(market, last),
    orNull(getCategory(market.slug, last).then((r) => r.data)).catch(() => null),
    getCategoryTree(market.slug)
      .then((r) => r.data)
      .catch(() => [] as CategoryNode[]),
  ]);

  if (!detail && !page) notFound();

  // Canonical path: root (first breadcrumb) + the category itself.
  const lineage = detail ? detail.breadcrumbs.map((c) => c.slug) : [];
  const path = lineage.length > 1 ? [lineage[0], last] : lineage.length === 1 ? [last] : segments;
  const basePath = `/${market.slug}/${path.join("/")}`;
  const children: Category[] = (detail?.children ?? []).map((c) => ({ slug: c.slug, name: c.name, image: c.image }));

  const ctx: BlockContext = {
    market,
    categories,
    category: detail ? { slug: detail.slug, name: detail.name, children, path } : null,
    listing: { query, basePath },
  };
  const tree: TreeNode[] = nodes.length > 0 ? categoryTreeWithCounts(market, nodes, lineage) : categoryTree(market, categories, path);

  if (page && page.template !== "home") {
    const zones = page.zones;
    const hasListing = (zones.listing?.length ?? 0) > 0;
    return (
      <div className="flex flex-col gap-6">
        <PageZone blocks={zones.header} ctx={ctx} />
        <PageZone blocks={zones.top} ctx={ctx} />
        <PageZone blocks={zones.main} ctx={ctx} />
        {hasListing ? (
          <PageZone blocks={zones.listing} ctx={ctx} />
        ) : detail ? (
          <ProductListing market={market} query={query} basePath={basePath} category={detail.slug} tree={tree} />
        ) : null}
        <PageZone blocks={zones.bottom} ctx={ctx} />
      </div>
    );
  }

  // Default category template: header, sub-category tiles + chips when the category has children, listing.
  const hasChildren = children.length > 0;
  return (
    <div className="flex flex-col gap-5">
      <CategoryHeader s={{}} ctx={ctx} />
      {hasChildren ? <SubcategoryTiles s={{}} ctx={ctx} /> : null}
      {hasChildren ? <SubcategoryChips s={{ sticky: false }} ctx={ctx} /> : null}
      <ProductListing market={market} query={query} basePath={basePath} category={detail!.slug} tree={tree} />
    </div>
  );
}
