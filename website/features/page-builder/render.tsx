import type { ComponentType, ReactNode } from "react";
import type { Block } from "@/lib/api/schemas";
import { BlockBoundary } from "./block-boundary";
import * as ads from "./blocks/ads";
import * as banners from "./blocks/banners";
import * as category from "./blocks/category";
import * as containers from "./blocks/containers";
import * as content from "./blocks/content";
import * as hero from "./blocks/hero";
import * as navigation from "./blocks/navigation";
import * as products from "./blocks/products";
import type { BlockContext, Settings } from "./settings";

type BlockProps = { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null; renderChild: containers.ChildRenderer };

/**
 * Block registry v2 (docs/design/page-builder-blocks.md, 51 types).
 * Unknown types render nothing (forward compatibility, rule 4) and every block
 * sits in its own error boundary so one bad block never breaks the page.
 * Extension point: add a component here when the backend registry grows.
 */
export const BLOCKS: Record<string, ComponentType<BlockProps>> = {
  // A. Hero & sliders
  hero_carousel: hero.HeroCarousel,
  hero_bento: hero.HeroBento,
  banner_slider: hero.BannerSlider,
  banner_scroller: hero.BannerScroller,
  // B. Banners & grids
  promo_banner: banners.PromoBanner,
  banner_grid: banners.BannerGrid,
  banner_strip: banners.BannerStrip,
  banner_full: banners.BannerFull,
  countdown_strip: banners.CountdownStrip,
  // C. Category / brand navigation
  tile_scroller: navigation.TileScroller,
  category_tiles: navigation.CategoryTiles,
  category_showcase: navigation.CategoryShowcase,
  brand_carousel: navigation.BrandCarousel,
  segment_tabs: navigation.SegmentTabs,
  quick_links: navigation.QuickLinks,
  // D. Products & deals
  product_carousel: products.ProductCarousel,
  product_grid: products.ProductGridBlock,
  item_carousel: products.ItemCarousel,
  mega_deals: products.MegaDeals,
  hero_offer_list: products.HeroOfferList,
  frenzy_deals: products.FrenzyDeals,
  deal_grid: products.DealGrid,
  offer_cards: products.OfferCards,
  tile_grid: products.TileGrid,
  // E. Layout containers
  panel_row: containers.PanelRow,
  section_band: containers.SectionBand,
  // F. Sponsored / ads
  sponsored_brand_banner: ads.SponsoredBrandBanner,
  sponsored_product: ads.SponsoredProduct,
  sponsored_brand_video: ads.SponsoredBrandVideo,
  sponsored_video: ads.SponsoredVideo,
  ad_slot: ads.AdSlot,
  // G. Listing zone
  product_listing: category.ProductListingBlock,
  // H. Content, marketplace & engagement
  verticals: content.Verticals,
  creators_carousel: content.CreatorsCarousel,
  classified_carousel: content.ClassifiedCarousel,
  travel_packages_carousel: content.TravelPackagesCarousel,
  properties_carousel: content.PropertiesCarousel,
  video_block: content.VideoBlock,
  live_streams_strip: content.LiveStreamsStrip,
  html: content.HtmlBlock,
  seo_text: content.SeoText,
  popular_searches: content.PopularSearches,
  app_download: content.AppDownload,
  newsletter_signup: content.NewsletterSignup,
  vendor_spotlight: content.VendorSpotlight,
  // I. Sub-category navigation
  subcategory_tiles: category.SubcategoryTiles,
  subcategory_hub: category.SubcategoryHub,
  subcategory_chips: category.SubcategoryChips,
  // J. Page headers
  category_header: category.CategoryHeader,
  brand_header: category.BrandHeader,
  campaign_hero: category.CampaignHero,
};

export function renderBlock(block: { type: string; settings: Settings; data?: Record<string, unknown> | null; id?: string | number | null }, ctx: BlockContext, key: number | string): ReactNode {
  const Component = BLOCKS[block.type];
  if (!Component) return null;
  return (
    <BlockBoundary key={key} type={block.type}>
      <Component s={block.settings ?? {}} ctx={ctx} data={block.data} renderChild={(child, childCtx, childKey) => renderBlock(child, childCtx, `${key}-${childKey}`)} />
    </BlockBoundary>
  );
}

/** Render one zone of a published page (`zones.main`, `zones.header`, …). */
export function PageZone({ blocks, ctx, className = "flex flex-col gap-6" }: { blocks: Block[] | undefined; ctx: BlockContext; className?: string }) {
  if (!blocks || blocks.length === 0) return null;
  return <div className={className}>{blocks.map((block, i) => renderBlock({ ...block, settings: block.settings as Settings }, ctx, block.id ?? i))}</div>;
}
