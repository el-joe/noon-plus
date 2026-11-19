import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { Crumbs } from "@/features/content/pagination";
import { toListingQuery } from "@/features/listing/product-listing";
import { SellerFollowButton } from "@/features/seller/follow-button";
import { SellerAbout, SellerHeader, SellerPolicies, SellerProducts, SellerReviews, SellerTabs, type StoreTab } from "@/features/seller/storefront";
import { orNull } from "@/lib/api/client";
import { getSeller, sellerHref, type SellerProfile } from "@/lib/api/sellers";
import { getT } from "@/lib/i18n";
import { parseMarket, type Market } from "@/lib/markets";

const SLUG = /^[A-Za-z0-9._~-]{1,200}$/;

async function load(params: PageProps<"/[market]/seller/[slug]">["params"]): Promise<{ market: Market; seller: SellerProfile | null }> {
  const { market: m, slug } = await params;
  const market = parseMarket(m);
  let key = slug;
  try {
    key = decodeURIComponent(slug);
  } catch {
    notFound();
  }
  if (!market || !SLUG.test(key)) notFound();
  const seller = await orNull(getSeller(market.slug, key).then((r) => r.data));
  return { market, seller };
}

export async function generateMetadata({ params }: PageProps<"/[market]/seller/[slug]">): Promise<Metadata> {
  const { market, seller } = await load(params).catch(() => ({ market: null, seller: null }));
  if (!market) return {};
  const t = getT(market.locale);
  if (!seller) return { title: t("storefront.seller.notFound"), robots: { index: false } };
  const description = seller.description?.slice(0, 160) || t("storefront.seller.metaDescription", { name: seller.name, count: seller.product_count });
  return {
    title: seller.name,
    description,
    alternates: { canonical: sellerHref(market.slug, seller.slug) },
    openGraph: { title: seller.name, description, images: seller.logo ? [seller.logo] : undefined },
  };
}

/**
 * `/seller/{slug}` — product store page (QC-008, FEATURE_MATRIX §3): header
 * with rating, badges and follow, store policies, then the Products tab (the
 * PLP rail on `sellers/{slug}/products`) or the Reviews tab (`?tab=reviews`).
 * A store that is not active in this market is a 404 (lib/missing-routes.ts).
 */
export default function SellerPage(props: PageProps<"/[market]/seller/[slug]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <SellerContent {...props} />
      </Suspense>
    </Container>
  );
}

async function SellerContent({ params, searchParams }: PageProps<"/[market]/seller/[slug]">) {
  const { market, seller } = await load(params);
  if (!seller) notFound();
  const raw = await searchParams;
  const tab: StoreTab = raw.tab === "reviews" ? "reviews" : "products";
  const basePath = sellerHref(market.slug, seller.slug);
  const t = getT(market.locale);
  const { tab: _tab, ...rest } = toListingQuery(raw);
  void _tab;
  const page = Number(raw.page);

  return (
    <div className="flex flex-col gap-5">
      <Crumbs market={market} items={[{ label: t("storefront.seller.store") }, { label: seller.name }]} />
      <SellerHeader market={market} seller={seller} follow={<SellerFollowButton slug={seller.slug} initialFollowers={seller.followers} />} />
      <SellerPolicies market={market} seller={seller} />
      <SellerAbout market={market} seller={seller} />
      <SellerTabs market={market} seller={seller} tab={tab} basePath={basePath} />
      {tab === "reviews" ? (
        <SellerReviews market={market} seller={seller} page={Number.isInteger(page) && page > 1 ? page : 1} basePath={basePath} />
      ) : (
        <SellerProducts market={market} seller={seller} query={rest} basePath={basePath} />
      )}
    </div>
  );
}
