import clsx from "clsx";
import { ChevronRight, Play } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";
import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { AdLabel } from "@/components/ui/misc";
import { getAdPlacements } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import { money as moneySchema, productCard, type AdPlacement, type Money } from "@/lib/api/schemas";
import { formatAmount } from "@/lib/format";
import { getT, type ServerT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { Countdown } from "../countdown";
import { list, num, resolveLink, safeUrl, str, type BlockContext, type Settings } from "../settings";
import { BlockSection, CARD_TONES, cardTone, StyledCard } from "./shared";

/**
 * Category page blocks (design docs/design/reference/category-page-target.webp,
 * registry group K): promo slides, promo tiles, brand tiles, "Gifts they'll
 * love", Mega Deals columns and the sponsored brand row. Prices and
 * discounts in captions always come from BlockResolver data (real offers),
 * never from typed copy.
 */

type Data = Record<string, unknown> | null | undefined;

/** "AED 499" — whole amounts for 2-decimal currencies, 3 decimals for OMR / KWD / BHD / JOD. */
export function shortMoney(value: Money | null | undefined, market: Market): string | null {
  if (!value) return null;
  const amount = Number(value.amount);
  if (!Number.isFinite(amount)) return null;
  const decimals = market.country.decimals >= 3 ? 3 : amount >= 10 || Number.isInteger(amount) ? 0 : 2;
  return `${value.currency} ${formatAmount(amount.toFixed(decimals))}`;
}

/** Offer caption from real data: "Up to 40% off" when something is on sale, else "From AED 499". */
export function offerCaption(t: ServerT, market: Market, minPrice: Money | null | undefined, maxDiscount: number | null | undefined, short = false): string | null {
  if ((maxDiscount ?? 0) >= 5) return t(short ? "categoryPage.upTo" : "categoryPage.upToOff", { percent: String(maxDiscount) });
  const price = shortMoney(minPrice, market);
  return price ? t("categoryPage.from", { price }) : null;
}

function cards(raw: unknown): ProductCardData[] {
  return (Array.isArray(raw) ? raw : []).flatMap((p) => {
    const parsed = productCard.safeParse(p);
    return parsed.success ? [cardFromApi(parsed.data)] : [];
  });
}

function parseMoney(raw: unknown): Money | null {
  const parsed = moneySchema.safeParse(raw);
  return parsed.success ? parsed.data : null;
}

/** 52 category_hero — promo slides (coloured cards with sticker, title, subline, code, product image) and dots. */
export function CategoryHero({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const slides = list(s, "slides").filter((slide) => str(slide, "title", ctx.market.locale) || str(slide, "image"));
  if (slides.length === 0) return null;
  return (
    <Rail itemClass="basis-full" gap="gap-0" loop={slides.length > 1} dots={slides.length > 1} autoplayMs={slides.length > 1 ? num(s, "autoplay", 6) * 1000 : undefined}>
      {slides.map((slide, i) => (
        <StyledCard key={i} s={{ tone: "brand", ...slide }} ctx={ctx} size="hero" className="min-h-64 md:min-h-72" />
      ))}
    </Rail>
  );
}

const TILE_TONES = ["brand", "light", "light", "subtle"] as const;

/** 53 category_promo_tiles — a big dark card and four coloured tiles with captions from the catalogue. */
export function CategoryPromoTiles({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Data }) {
  const t = getT(ctx.market.locale);
  const captions = (data?.captions && typeof data.captions === "object" ? data.captions : {}) as Record<string, { min_price?: unknown; max_discount?: number }>;
  const caption = (tile: Settings) => {
    const slug = str(tile, "category");
    const stats = slug ? captions[slug] : undefined;
    return (stats ? offerCaption(t, ctx.market, parseMoney(stats.min_price), stats.max_discount) : null) ?? str(tile, "caption", ctx.market.locale) ?? str(tile, "subtitle", ctx.market.locale);
  };
  const hero = s.hero && typeof s.hero === "object" ? (s.hero as Settings) : null;
  const tiles = list(s, "tiles").slice(0, 4);
  if (!hero && tiles.length === 0) return null;
  return (
    <BlockSection s={{ ...s, surface: false }} ctx={ctx}>
      <div className="grid gap-3 md:grid-cols-4 md:grid-rows-2">
        {hero ? <StyledCard s={{ tone: "dark", ...hero, subtitle: str(hero, "subtitle", ctx.market.locale) ?? caption(hero) }} ctx={ctx} size="hero" className="min-h-64 md:col-span-2 md:row-span-2" /> : null}
        {tiles.map((tile, i) => {
          const tone = str(tile, "tone") ? cardTone(tile) : TILE_TONES[i];
          const href = resolveLink(str(tile, "link"), ctx.market);
          const image = safeUrl(str(tile, "image"));
          const body = (
            <>
              <h3 className="text-h3 font-extrabold leading-tight">{str(tile, "title", ctx.market.locale)}</h3>
              {caption(tile) ? <p className={clsx("mt-1 text-body-sm", tone === "dark" ? "text-white/80" : "text-secondary")}>{caption(tile)}</p> : null}
              {image ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={image} alt="" loading="lazy" className="absolute bottom-2 end-2 h-20 w-20 object-contain md:h-24 md:w-24" />
              ) : null}
            </>
          );
          const classes = clsx("relative min-h-32 overflow-hidden rounded-lg p-4 md:min-h-40", CARD_TONES[tone], href && "transition-shadow hover:shadow-md");
          return href ? (
            <Link key={i} href={href} className={classes}>
              {body}
            </Link>
          ) : (
            <div key={i} className={classes}>
              {body}
            </div>
          );
        })}
      </div>
    </BlockSection>
  );
}

type BrandTile = { slug: string; name: string; logo?: string | null; image?: { url?: string | null } | null; product_count?: number; min_price?: unknown; max_discount?: number };

/** 54 category_brand_tiles — brands of the category with a product image and an offer caption. */
export function CategoryBrandTiles({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Data }) {
  const tiles = (Array.isArray(data?.tiles) ? data.tiles : []) as BrandTile[];
  if (tiles.length === 0) return null;
  const t = getT(ctx.market.locale);
  const category = str(s, "category");
  return (
    <BlockSection s={{ ...s, surface: false }} ctx={ctx}>
      <Rail itemClass="basis-[40%] sm:basis-1/4 lg:basis-1/6">
        {tiles.map((tile) => {
          const image = safeUrl(tile.image?.url ?? tile.logo ?? null);
          const caption = offerCaption(t, ctx.market, parseMoney(tile.min_price), tile.max_discount);
          return (
            <Link key={tile.slug} href={`/${ctx.market.slug}/brands/${tile.slug}${category ? `?category=${category}` : ""}`} className="flex h-full flex-col items-center gap-2 rounded-lg bg-surface p-3 text-center transition-shadow hover:shadow-md">
              <span className="flex aspect-square w-full items-center justify-center overflow-hidden rounded-md bg-page">
                {image ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={image} alt="" loading="lazy" className="max-h-full max-w-full object-contain p-2" />
                ) : (
                  <span className="text-h2 font-bold text-muted">{tile.name.slice(0, 1)}</span>
                )}
              </span>
              <span className="text-body-sm font-bold uppercase tracking-wide">{tile.name}</span>
              {caption ? <span className="text-caption font-semibold text-success-text">{caption}</span> : null}
            </Link>
          );
        })}
      </Rail>
    </BlockSection>
  );
}

/** 55 category_gift_band — yellow band: dark "Gifts they'll love" card + product mini-cards with category stickers. */
export function CategoryGiftBand({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Data }) {
  const products = Array.isArray(data?.products) ? (data.products as unknown[]) : [];
  const list_ = cards(products);
  if (list_.length === 0) return null;
  const categories = (data?.categories && typeof data.categories === "object" ? data.categories : {}) as Record<string, string>;
  const stickers = products.map((p) => {
    const slug = p && typeof p === "object" ? (p as { category?: unknown }).category : null;
    return typeof slug === "string" ? (categories[slug] ?? null) : null;
  });
  const href = resolveLink(str(s, "link"), ctx.market);
  const intro = (
    <>
      {str(s, "sticker", ctx.market.locale) ? <span className="rounded-xs bg-brand px-1.5 py-0.5 text-caption font-semibold text-ink">{str(s, "sticker", ctx.market.locale)}</span> : null}
      <h2 className="text-h2 font-extrabold leading-tight">{str(s, "title", ctx.market.locale)}</h2>
      {str(s, "subtitle", ctx.market.locale) ? <p className="text-body-sm text-white/80">{str(s, "subtitle", ctx.market.locale)}</p> : null}
    </>
  );
  return (
    <section className="rounded-lg bg-brand p-3 md:p-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {href ? (
          <Link href={href} className="flex min-h-40 flex-col items-start gap-2 rounded-lg bg-ink p-5 text-white sm:col-span-2 lg:col-span-1">
            {intro}
          </Link>
        ) : (
          <div className="flex min-h-40 flex-col items-start gap-2 rounded-lg bg-ink p-5 text-white sm:col-span-2 lg:col-span-1">{intro}</div>
        )}
        {list_.slice(0, 4).map((product, i) => (
          <div key={product.slug} className="relative">
            <ProductCard product={product} compact />
            {stickers[i] ? <span className="pointer-events-none absolute start-2 top-2 z-10 rounded-xs bg-brand px-1.5 py-0.5 text-caption font-semibold text-ink">{stickers[i]}</span> : null}
          </div>
        ))}
      </div>
    </section>
  );
}

type DealColumn = { slug: string; name: string; product: unknown };

/** 56 category_deal_columns — Mega Deals band: countdown and one deal column per sub-category. */
export function CategoryDealColumns({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Data }) {
  const columns = (Array.isArray(data?.columns) ? data.columns : []) as DealColumn[];
  const rows = columns.flatMap((column) => {
    const [product] = cards([column.product]);
    return product ? [{ ...column, card: product }] : [];
  });
  if (rows.length === 0) return null;
  const t = getT(ctx.market.locale);
  const endsAt = typeof data?.ends_at === "string" ? data.ends_at : null;
  const saleSlug = typeof data?.sale_slug === "string" ? data.sale_slug : null;
  return (
    <section className="rounded-lg bg-success-subtle p-3 md:p-4">
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <h2 className="text-h2 font-extrabold">{str(s, "title", ctx.market.locale) ?? t("categoryPage.megaDeal")}</h2>
        {endsAt ? (
          <span className="flex items-center gap-2 text-body-sm text-secondary">
            {t("categoryPage.endsIn")}
            <Countdown endsAt={endsAt} tone="danger" />
          </span>
        ) : null}
        {saleSlug ? (
          <Link href={`/${ctx.market.slug}/flash-sale/${encodeURIComponent(saleSlug)}`} className="ms-auto rounded-sm border border-line bg-surface px-2.5 py-1 text-caption font-semibold uppercase">
            {t("common.viewAll")}
          </Link>
        ) : null}
      </div>
      <div className="scrollbar-none -mx-1 flex gap-3 overflow-x-auto px-1 pb-1">
        {rows.map((row) => (
          <div key={row.slug} className="flex w-[46%] shrink-0 flex-col overflow-hidden rounded-lg bg-surface sm:w-1/3 lg:w-auto lg:flex-1">
            <Link href={`/${ctx.market.slug}/${row.slug}?deals=1`} className="bg-brand px-2 py-1.5 text-center text-caption font-semibold text-ink">
              {t("categoryPage.dealsFor", { name: row.name })}
            </Link>
            <div className="relative p-1">
              <ProductCard product={row.card} compact />
              <span className="pointer-events-none absolute start-2 top-2 z-10 rounded-xs bg-danger px-1.5 py-0.5 text-caption font-semibold text-white">{t("categoryPage.megaDeal")}</span>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

/**
 * Sponsored brand row (design "Explore more" and the listing insert every
 * 4 rows): the advertised brand, headline, CTA and its products, or the
 * booking's video. Always labelled "Ad"; nothing renders without a live booking.
 */
export function SponsoredBrandRow({ ad, market }: { ad: AdPlacement; market: Market }) {
  const t = getT(market.locale);
  const href = resolveLink(ad.link_url, market);
  const products = cards(ad.products ?? []).slice(0, 4);
  const name = ad.brand?.name ?? ad.store?.name ?? null;
  const video = ad.creative_type === "video" || ad.video ? safeUrl(ad.video ?? null) : null;
  const image = safeUrl(ad.image);
  const logo = safeUrl(ad.brand?.logo ?? null);
  return (
    <section className="relative overflow-hidden rounded-lg border border-line bg-surface p-3 md:p-4" aria-label={ad.label || t("categoryPage.sponsored")}>
      <AdLabel className="absolute end-2 top-2 z-10">{ad.label || t("categoryPage.ad")}</AdLabel>
      <div className="flex flex-col gap-3 md:flex-row md:items-stretch">
        {video ? (
          <div className="relative overflow-hidden rounded-lg bg-ink md:w-2/5">
            <video src={video} muted playsInline loop autoPlay preload="metadata" aria-label={ad.headline ?? name ?? t("categoryPage.sponsored")} className="aspect-video size-full object-cover" />
            <Play className="pointer-events-none absolute bottom-2 start-2 size-5 text-white" />
          </div>
        ) : (
          <div className="flex flex-col items-start justify-center gap-2 md:w-1/4">
            {logo ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={logo} alt={name ?? ""} className="h-10 max-w-40 object-contain" />
            ) : name ? (
              <span className="rounded-sm bg-ink px-3 py-1.5 text-body-sm font-bold uppercase tracking-wide text-white">{name}</span>
            ) : null}
            {ad.headline ? <p className="text-body font-semibold">{ad.headline}</p> : null}
            {href ? (
              <Link href={href} rel="sponsored" className="inline-flex items-center gap-1 rounded-sm bg-ink px-3 py-1.5 text-caption font-semibold text-white">
                {name ? t("categoryPage.shopBrand", { brand: name }) : t("common.viewAll")}
                <ChevronRight className="size-3.5 rtl:rotate-180" />
              </Link>
            ) : null}
          </div>
        )}
        {image && !video ? (
          <CreativeImage src={image} href={href} alt={ad.headline ?? name ?? ""} />
        ) : null}
        {products.length > 0 ? (
          <div className="grid min-w-0 flex-1 grid-cols-2 gap-3 sm:grid-cols-4">
            {products.map((product) => (
              <ProductCard key={product.slug} product={product} compact />
            ))}
          </div>
        ) : null}
      </div>
    </section>
  );
}

function CreativeImage({ src, href, alt }: { src: string; href: string | null; alt: string }) {
  // eslint-disable-next-line @next/next/no-img-element
  const img = <img src={src} alt={alt} loading="lazy" className="h-full max-h-40 w-full rounded-md object-contain" />;
  return (
    <div className="flex items-center justify-center md:w-1/4">
      {href ? (
        <Link href={href} rel="sponsored" className="block size-full">
          {img}
        </Link>
      ) : (
        img
      )}
    </div>
  );
}

/** Live bookings of a placement (category-scoped), with the advertised products. */
export async function sponsoredBrandAds(market: Market, placement: string, category: string | null): Promise<AdPlacement[]> {
  try {
    const ads = (await getAdPlacements(market.slug, placement, { ...(category ? { category } : {}), with: "products" })).data;
    return ads.filter((ad) => ad.image || ad.video || ad.headline || (ad.products?.length ?? 0) > 0);
  } catch {
    return [];
  }
}

async function Showcase({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const ads = await sponsoredBrandAds(ctx.market, str(s, "ad_slot_key") ?? str(s, "placement") ?? "sponsored_brand_banner", str(s, "category") ?? ctx.category?.path.at(-1) ?? null);
  if (ads.length === 0) return null;
  const title = str(s, "title", ctx.market.locale);
  return (
    <section className="flex flex-col gap-3">
      {title ? <h2 className="text-h3 font-semibold">{title}</h2> : null}
      {ads.slice(0, num(s, "max_items", 1)).map((ad, i) => (
        <SponsoredBrandRow key={`${ad.slot}-${i}`} ad={ad} market={ctx.market} />
      ))}
    </section>
  );
}

/** 57 sponsored_brand_showcase — "Explore more": live sponsored brand bookings of the slot, with products. */
export function SponsoredBrandShowcase({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  return (
    <Suspense fallback={null}>
      <Showcase s={s} ctx={ctx} />
    </Suspense>
  );
}
