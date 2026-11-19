import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { categoryTree, categoryTreeWithCounts, ProductListing, toListingQuery, type TreeNode } from "@/features/listing/product-listing";
import { CategoryBreadcrumb, SubcategoryChips, SubcategoryTiles, type CategoryChild } from "@/features/page-builder/blocks/category";
import { loadCmsPage } from "@/features/page-builder/cms-page";
import { PageZone } from "@/features/page-builder/render";
import { str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import { orNull } from "@/lib/api/client";
import { getCategory, getCategoryTree, getPage } from "@/lib/api/endpoints";
import type { Block, CategoryNode } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";
import { withoutBrandSuffix } from "@/lib/format";

const SEGMENT = /^[a-z0-9-]+$/;

/** Short country names for "Electronics in UAE" (design results header). */
const PLACES: Record<string, { en: string; ar: string }> = {
  uae: { en: "UAE", ar: "الإمارات" },
  omn: { en: "Oman", ar: "عُمان" },
  ksa: { en: "Saudi Arabia", ar: "السعودية" },
  egy: { en: "Egypt", ar: "مصر" },
  kwt: { en: "Kuwait", ar: "الكويت" },
  qat: { en: "Qatar", ar: "قطر" },
  bhr: { en: "Bahrain", ar: "البحرين" },
  jor: { en: "Jordan", ar: "الأردن" },
};

function placeName(market: Market): string {
  return PLACES[market.country.code]?.[market.locale] ?? market.country.name[market.locale];
}

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
  const title = withoutBrandSuffix(page?.seo?.title || page?.title || (category ? getT(market.locale)("categoryPage.inPlace", { name: category.name, place: placeName(market) }) : undefined));
  const description = page?.seo?.description || (category ? getT(market.locale)("listing.products", { count: category.product_count }) : undefined);
  return title ? { title, description: description ?? undefined, openGraph: { title, description: description ?? undefined } } : {};
}

/**
 * `/{market}/{category}[/{sub}…]` — category page (design
 * docs/design/reference/category-page-target.webp). The filter rail runs the
 * full height beside every category block: breadcrumb on top, then the
 * published page-builder page with the category's slug (template category:
 * `listing` chips first, `header`, `top` and `main` zones above the results,
 * `bottom` under them; the `listing` zone's product_listing sets the default
 * sort) or, without a page, sub-category chips and tiles. The last segment
 * is resolved with `categories/{slug}` (any depth); the rail tree uses
 * `categories/tree` with filtered counts from the facets.
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
  const children: CategoryChild[] = (detail?.children ?? []).filter((c) => c.product_count > 0).map((c) => ({ slug: c.slug, name: c.name, image: c.image, min_price: c.min_price ?? null, max_discount: c.max_discount ?? 0 }));
  const crumbs = detail
    ? detail.breadcrumbs.map((c, i) => ({ label: c.name, href: i === 0 ? `/${market.slug}/${c.slug}` : `/${market.slug}/${detail.breadcrumbs[0].slug}/${c.slug}` }))
    : [{ label: page?.title ?? last, href: basePath }];

  const ctx: BlockContext = {
    market,
    categories,
    category: detail ? { slug: detail.slug, name: detail.name, children, path } : null,
    listing: { query, basePath },
  };
  const tree: TreeNode[] = nodes.length > 0 ? categoryTreeWithCounts(market, nodes, lineage) : categoryTree(market, categories, path);
  const heading = detail ? getT(market.locale)("categoryPage.inPlace", { name: detail.name, place: placeName(market) }) : (page?.title ?? null);
  const subcategoryChips = children.slice(0, 6).map((c) => ({ label: c.name, href: `${basePath}/${c.slug}` }));
  const breadcrumb = <CategoryBreadcrumb market={market} crumbs={crumbs} />;

  if (page && page.template !== "home") {
    const zones = page.zones;
    const listingZone: Block[] = zones.listing ?? [];
    const listingBlock = listingZone.find((b) => b.type === "product_listing");
    const leadBlocks = listingZone.filter((b) => b.type !== "product_listing");
    const settings = (listingBlock?.settings ?? {}) as Settings;
    const listingQuery = { ...query };
    if (!listingQuery.sort && str(settings, "default_sort")) listingQuery.sort = str(settings, "default_sort") ?? undefined;
    const scope = str(settings, "scope");
    const category = detail?.slug ?? (scope?.startsWith("category:") ? scope.slice(9) : str(settings, "category"));
    const lead = (
      <>
        <PageZone blocks={leadBlocks} ctx={ctx} />
        <PageZone blocks={zones.header} ctx={ctx} />
        <PageZone blocks={zones.top} ctx={ctx} />
        <PageZone blocks={zones.main} ctx={ctx} />
      </>
    );
    const after = <PageZone blocks={zones.bottom} ctx={ctx} />;
    return (
      <div className="flex flex-col gap-4">
        {breadcrumb}
        {listingBlock || detail ? (
          <ProductListing market={market} query={listingQuery} basePath={basePath} category={category ?? null} tree={tree} lead={lead} after={after} heading={heading} subcategories={subcategoryChips} />
        ) : (
          <div className="flex flex-col gap-6">
            {lead}
            {after}
          </div>
        )}
      </div>
    );
  }

  // Default category template: sub-category boxes and circle tiles (with offer captions) above the results.
  const hasChildren = children.length > 0;
  const lead = hasChildren ? (
    <>
      <SubcategoryChips s={{ style: "boxes" }} ctx={ctx} />
      <SubcategoryTiles s={{ style: "circle" }} ctx={ctx} />
    </>
  ) : null;
  return (
    <div className="flex flex-col gap-4">
      {breadcrumb}
      <ProductListing market={market} query={query} basePath={basePath} category={detail!.slug} tree={tree} lead={lead} heading={heading} subcategories={subcategoryChips} />
    </div>
  );
}
