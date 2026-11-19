import Link from "next/link";
import { Suspense } from "react";
import { dealCards, flashSalePath } from "@/features/shop/deals";
import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { Skeleton } from "@/components/ui/misc";
import { orNull } from "@/lib/api/client";
import { getFlashSale } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import { flashSale, productCard, type FlashSale } from "@/lib/api/schemas";
import { formatAmount } from "@/lib/format";
import { getT } from "@/lib/i18n";
import { Countdown } from "../countdown";
import { background, item, items, list, num, str, type BlockContext, type Settings } from "../settings";
import { RecentlyViewed } from "./recently-viewed";
import { BlockSection, CardLink, ImageLink } from "./shared";

/** Products resolved server-side by BlockResolver (`data.products`): product cards (round 2). Bad rows are skipped. */
function resolvedProducts(data: Record<string, unknown> | null | undefined): ProductCardData[] {
  const raw = Array.isArray(data?.products) ? (data.products as unknown[]) : [];
  return raw.flatMap((p) => {
    const parsed = productCard.safeParse(p);
    return parsed.success ? [cardFromApi(parsed.data)] : [];
  });
}

/** 16 product_carousel — 1 or 2 rows; optional left banner. */
export function ProductCarousel({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const products = resolvedProducts(data);
  if (products.length === 0) return null;
  const rows = num(s, "rows", 1) >= 2 ? 2 : 1;
  const banner = s.banner && typeof s.banner === "object" ? item(s.banner as Settings, ctx.market.locale) : null;
  const columns: ProductCardData[][] = rows === 2 ? pairs(products) : products.map((p) => [p]);
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="flex gap-3">
        {banner?.image ? <ImageLink item={banner} ctx={ctx} className="hidden w-[232px] shrink-0 lg:block" /> : null}
        <div className="min-w-0 flex-1">
          <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
            {columns.map((col, i) => (
              <div key={i} className="flex flex-col gap-3">
                {col.map((p, j) => (
                  <ProductCard key={j} product={p} />
                ))}
              </div>
            ))}
          </Rail>
        </div>
      </div>
    </BlockSection>
  );
}

/** 17 product_grid — 4–6 columns × N rows. */
export function ProductGridBlock({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const products = resolvedProducts(data);
  if (products.length === 0) return null;
  const cols = Math.min(6, Math.max(4, num(s, "columns", 5)));
  const lg = { 4: "lg:grid-cols-4", 5: "lg:grid-cols-5", 6: "lg:grid-cols-6" }[cols as 4 | 5 | 6];
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className={`grid grid-cols-2 gap-3 md:grid-cols-3 ${lg}`}>
        {products.map((p, i) => (
          <ProductCard key={i} product={p} />
        ))}
      </div>
    </BlockSection>
  );
}

/** 18 item_carousel — recently viewed (this device). */
export function ItemCarousel({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  return <RecentlyViewed title={str(s, "title", ctx.market.locale) ?? t("blocks.recentlyViewed")} />;
}

async function FetchedSale({ id, ctx, render }: { id: number | string; ctx: BlockContext; render: (sale: FlashSale) => React.ReactNode }) {
  const sale = await orNull(getFlashSale(ctx.market.slug, id).then((r) => r.data)).catch(() => null);
  if (!sale || sale.deals.length === 0) return null;
  return <>{render(sale)}</>;
}

/** The block's flash sale: resolved server-side (`data.sale`, BlockResolver), else fetched by `flash_sale_id` / `flash_sale_slug`. */
function FlashSaleDeals({ s, data, ctx, render, fallback }: { s: Settings; data?: Record<string, unknown> | null; ctx: BlockContext; render: (sale: FlashSale) => React.ReactNode; fallback: React.ReactNode }) {
  if (data && "sale" in data) {
    const parsed = flashSale.safeParse(data.sale);
    return parsed.success && parsed.data.deals.length > 0 ? <>{render(parsed.data)}</> : null;
  }
  const id = flashId(s);
  if (!id) return null;
  return (
    <Suspense fallback={fallback}>
      <FetchedSale id={id} ctx={ctx} render={render} />
    </Suspense>
  );
}

function flashId(s: Settings): number | string | null {
  const id = num(s, "flash_sale_id", 0);
  return id > 0 ? id : (str(s, "flash_sale_slug") ?? null);
}

/** 19 mega_deals — coloured band, countdown, deal cards (`flash_sale_id` or `flash_sale_slug`). */
export function MegaDeals({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  return (
    <FlashSaleDeals
      s={s}
      data={data}
      ctx={ctx}
      fallback={<Skeleton className="h-80 w-full" />}
      render={(sale) => (
        <section className="rounded-lg p-4" style={{ backgroundColor: background(s) ?? "var(--color-brand-subtle)" }}>
          <div className="mb-3 flex flex-wrap items-center gap-3">
            <h2 className="text-h3 font-semibold">{str(s, "title", ctx.market.locale) ?? sale.name}</h2>
            <span className="text-body-sm text-secondary">{t("blocks.endsIn")}</span>
            <Countdown endsAt={sale.ends_at} tone="danger" />
            <AllDeals sale={sale} ctx={ctx} />
          </div>
          <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
            {dealCards(sale).map((p, i) => (
              <ProductCard key={i} product={p} />
            ))}
          </Rail>
        </section>
      )}
    />
  );
}

function AllDeals({ sale, ctx }: { sale: FlashSale; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  return (
    <Link href={`/${ctx.market.slug}/flash-sale/${flashSalePath(sale)}`} className="ms-auto shrink-0 rounded-sm bg-ink px-2.5 py-1 text-caption font-semibold uppercase text-white">
      {t("blocks.allDeals")}
    </Link>
  );
}

/** 20 hero_offer_list — band + hero visual + offers (flash sale deals or editor items). */
export function HeroOfferList({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const hero = item({ image: s.hero_image, link: s.link, title: s.title } as Settings, ctx.market.locale);
  const offers = items(s, "items", ctx.market.locale);
  const band = (children: React.ReactNode) => (
    <section className="grid gap-3 rounded-lg p-4 md:grid-cols-[280px_1fr]" style={{ backgroundColor: background(s) ?? "var(--color-brand-subtle)" }}>
      <ImageLink item={hero} ctx={ctx} className="aspect-[3/4] w-full md:aspect-auto md:h-full" />
      <div className="min-w-0">{children}</div>
    </section>
  );
  if (flashId(s) || (data && "sale" in data)) {
    return (
      <FlashSaleDeals
        s={s}
        data={data}
        ctx={ctx}
        fallback={<Skeleton className="h-72 w-full" />}
        render={(sale) =>
          band(
            <Rail itemClass="basis-[46%] sm:basis-1/3 lg:basis-1/4">
              {dealCards(sale).map((p, i) => (
                <ProductCard key={i} product={p} />
              ))}
            </Rail>,
          )
        }
      />
    );
  }
  if (offers.length === 0) return null;
  return band(
    <Rail itemClass="basis-[46%] sm:basis-1/3 lg:basis-1/4">
      {offers.map((o, i) => (
        <div key={i} className="flex flex-col gap-1.5">
          <ImageLink item={o} ctx={ctx} className="aspect-square w-full bg-surface" />
          {o.sticker ? <span className="self-start rounded-xs bg-ink px-1.5 text-caption text-white">{o.sticker}</span> : null}
          {o.label ? <span className="text-body">{o.label}</span> : null}
        </div>
      ))}
    </Rail>,
  );
}

/** 21 frenzy_deals — limited-quantity deals with claimed bar and timer. */
export function FrenzyDeals({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  return <MegaDeals s={{ background: "var(--color-surface)", ...s }} ctx={ctx} data={data} />;
}

/** Red sticker of a mini deal (home "Mega deals"): limited stock, ends today, else flash deal. */
function dealSticker(sale: FlashSale, claimed: number, t: ReturnType<typeof getT>): string {
  if (claimed >= 50) return t("blocks.limitedStock");
  if (new Date(sale.ends_at).getTime() - Date.now() < 24 * 3600 * 1000) return t("blocks.endsToday");
  return t("blocks.flashDeal");
}

/** 22 deal_grid — 2×2 mini deals + red countdown + ALL DEALS (home "Mega deals" panel). */
export function DealGrid({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  return (
    <FlashSaleDeals
      s={s}
      data={data}
      ctx={ctx}
      fallback={<Skeleton className="h-80 w-full" />}
      render={(sale) => (
        <section className="h-full rounded-lg bg-surface p-4">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <h2 className="text-h4 font-semibold">{str(s, "title", ctx.market.locale) ?? sale.name}</h2>
            <span className="text-caption text-secondary">{t("blocks.endsIn")}</span>
            <Countdown endsAt={sale.ends_at} tone="danger" />
            <AllDeals sale={sale} ctx={ctx} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            {sale.deals.slice(0, num(s, "max_items", 4)).map((deal) => {
              const percent = deal.price && deal.was_price && Number(deal.was_price.amount) > Number(deal.price.amount) ? Math.round((1 - Number(deal.price.amount) / Number(deal.was_price.amount)) * 100) : null;
              return (
                <Link key={deal.listing_id} href={`/${ctx.market.slug}/products/${encodeURIComponent(deal.product.slug)}`} className="flex flex-col gap-1">
                  <div className="relative aspect-[4/3] overflow-hidden rounded-md bg-subtle/40">
                    {deal.product.image?.url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={deal.product.image.url} alt={deal.product.image.alt ?? deal.product.title} loading="lazy" className="size-full object-contain p-1" />
                    ) : null}
                    <span className="absolute start-1 top-1 rounded-xs bg-danger px-1.5 py-0.5 text-caption font-semibold text-white">
                      {deal.sold_out ? t("blocks.soldOut") : dealSticker(sale, deal.claimed_percent, t)}
                    </span>
                  </div>
                  <span className="line-clamp-1 text-body-sm">{deal.product.title}</span>
                  {deal.price ? (
                    <span className="flex flex-wrap items-baseline gap-1">
                      <span className="text-caption font-medium">{deal.price.currency}</span>
                      <span className="text-body font-bold">{formatAmount(deal.price.amount)}</span>
                      {percent ? <span className="text-caption font-medium text-success-text">{t("blocks.percentOff", { percent })}</span> : null}
                    </span>
                  ) : null}
                </Link>
              );
            })}
          </div>
        </section>
      )}
    />
  );
}

/**
 * 23 offer_cards — coupon cards (home "Maximize your savings"): code badge,
 * image, title, caption. Items whose `code` is not a live coupon are hidden
 * (`data.codes` from BlockResolver).
 */
export function OfferCards({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  const live = Array.isArray(data?.codes) ? new Set((data.codes as unknown[]).filter((c): c is string => typeof c === "string")) : null;
  const raw = list(s, "items").filter((entry) => {
    const code = str(entry, "code");
    return !code || !live || live.has(code.toUpperCase());
  });
  if (raw.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass="basis-[60%] sm:basis-1/3 lg:basis-1/4">
        {raw.map((entry, i) => {
          const card = item(entry, ctx.market.locale);
          const code = str(entry, "code");
          return (
            <CardLink key={i} link={card.link} ctx={ctx} className="flex h-full flex-col items-center gap-2 p-2 text-center">
              <span className="relative block aspect-[4/3] w-full">
                {card.image ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={card.image} alt={card.alt} loading="lazy" className="size-full object-contain" />
                ) : null}
                {code ? <span className="absolute start-0 top-0 rounded-xs bg-ink px-1.5 py-0.5 text-caption font-semibold text-white">{t("blocks.code", { code })}</span> : null}
              </span>
              {card.label ? <span className="text-body font-semibold text-ink">{card.label}</span> : null}
              {card.caption ? <span className="text-caption text-secondary">{card.caption}</span> : null}
            </CardLink>
          );
        })}
      </Rail>
    </BlockSection>
  );
}

/** 24 tile_grid — "More reasons to shop" 2×2 (items may name a `category`, resolved into `data.tiles`). */
export function TileGrid({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const resolved = Array.isArray(data?.tiles) ? (data.tiles as Settings[]) : null;
  const tiles = (resolved ? resolved.map((x) => item(x, ctx.market.locale)) : items(s, "items", ctx.market.locale)).slice(0, 4);
  if (tiles.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx} className="h-full">
      <div className="grid grid-cols-2 gap-3">
        {tiles.map((tile, i) => (
          <CardLink key={i} link={tile.link} ctx={ctx} className="flex flex-col gap-1">
            <span className="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-md bg-brand-subtle">
              {tile.image ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={tile.image} alt={tile.alt} loading="lazy" className="size-full object-contain p-1" />
              ) : null}
            </span>
            {tile.label ? <span className="text-body-sm font-medium text-ink">{tile.label}</span> : null}
            {tile.caption ? <span className="text-caption text-secondary">{tile.caption}</span> : null}
          </CardLink>
        ))}
      </div>
    </BlockSection>
  );
}

function pairs<T>(values: T[]): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < values.length; i += 2) out.push(values.slice(i, i + 2));
  return out;
}
