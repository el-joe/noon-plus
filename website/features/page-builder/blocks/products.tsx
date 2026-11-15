import Link from "next/link";
import { Suspense } from "react";
import { dealCards, flashSalePath } from "@/features/shop/deals";
import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { Skeleton } from "@/components/ui/misc";
import { orNull } from "@/lib/api/client";
import { getFlashSale } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import { productCard, type FlashSale } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { Countdown } from "../countdown";
import { background, item, items, num, str, type BlockContext, type Settings } from "../settings";
import { RecentlyViewed } from "./recently-viewed";
import { BlockSection, ImageLink } from "./shared";

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

async function FlashSaleDeals({ id, ctx, render }: { id: number; ctx: BlockContext; render: (sale: FlashSale) => React.ReactNode }) {
  const sale = await orNull(getFlashSale(ctx.market.slug, id).then((r) => r.data)).catch(() => null);
  if (!sale || sale.deals.length === 0) return null;
  return <>{render(sale)}</>;
}


function flashId(s: Settings): number | null {
  const id = num(s, "flash_sale_id", 0);
  return id > 0 ? id : null;
}

/** 19 mega_deals — coloured band, countdown, deal cards. */
export function MegaDeals({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const id = flashId(s);
  if (!id) return null;
  const t = getT(ctx.market.locale);
  return (
    <Suspense fallback={<Skeleton className="h-80 w-full" />}>
      <FlashSaleDeals
        id={id}
        ctx={ctx}
        render={(sale) => (
          <section className="rounded-lg p-4" style={{ backgroundColor: background(s) ?? "#fff8e6" }}>
            <div className="mb-3 flex flex-wrap items-center gap-3">
              <h2 className="text-h3 font-semibold">{str(s, "title", ctx.market.locale) ?? sale.name}</h2>
              <span className="text-body-sm text-secondary">{t("blocks.endsIn")}</span>
              <Countdown endsAt={sale.ends_at} tone="danger" />
            </div>
            <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
              {dealCards(sale).map((p, i) => (
                <ProductCard key={i} product={p} />
              ))}
            </Rail>
          </section>
        )}
      />
    </Suspense>
  );
}

/** 20 hero_offer_list — band + hero visual + offers (flash sale deals or editor items). */
export function HeroOfferList({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const hero = item({ image: s.hero_image, link: s.link, title: s.title } as Settings, ctx.market.locale);
  const id = flashId(s);
  const offers = items(s, "items", ctx.market.locale);
  const band = (children: React.ReactNode) => (
    <section className="grid gap-3 rounded-lg p-4 md:grid-cols-[280px_1fr]" style={{ backgroundColor: background(s) ?? "#fff8e6" }}>
      <ImageLink item={hero} ctx={ctx} className="aspect-[3/4] w-full md:aspect-auto md:h-full" />
      <div className="min-w-0">{children}</div>
    </section>
  );
  if (id) {
    return (
      <Suspense fallback={<Skeleton className="h-72 w-full" />}>
        <FlashSaleDeals
          id={id}
          ctx={ctx}
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
      </Suspense>
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
export function FrenzyDeals({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  return <MegaDeals s={{ background: "#ffffff", ...s }} ctx={ctx} />;
}

/** 22 deal_grid — 2×2 mini deals + countdown + All deals. */
export function DealGrid({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const id = flashId(s);
  if (!id) return null;
  const t = getT(ctx.market.locale);
  return (
    <Suspense fallback={<Skeleton className="h-80 w-full" />}>
      <FlashSaleDeals
        id={id}
        ctx={ctx}
        render={(sale) => (
          <section className="rounded-lg bg-surface p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="text-h4 font-semibold">{str(s, "title", ctx.market.locale) ?? sale.name}</h2>
              <Countdown endsAt={sale.ends_at} tone="danger" />
            </div>
            <div className="grid grid-cols-2 gap-3">
              {dealCards(sale)
                .slice(0, 4)
                .map((p, i) => (
                  <ProductCard key={i} product={p} compact />
                ))}
            </div>
            <Link href={`/${ctx.market.slug}/flash-sale/${flashSalePath(sale)}`} className="mt-3 block text-end text-body-sm font-semibold text-link">
              {t("blocks.allDeals")}
            </Link>
          </section>
        )}
      />
    </Suspense>
  );
}

/** 23 offer_cards — coupon cards (image, title, code). */
export function OfferCards({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  const raw = Array.isArray(s.items) ? (s.items as Settings[]) : [];
  if (raw.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass="basis-[70%] sm:basis-1/3 lg:basis-1/4">
        {raw.map((entry, i) => {
          const card = item(entry, ctx.market.locale);
          const code = str(entry, "code");
          return (
            <div key={i} className="flex h-full flex-col gap-2 rounded-lg border border-line bg-surface p-3">
              {card.image ? <ImageLink item={card} ctx={ctx} className="aspect-[2/1] w-full" imgClass="size-full object-contain" /> : null}
              {card.label ? <p className="text-body font-medium">{card.label}</p> : null}
              {code ? <p className="self-start rounded-xs bg-brand-subtle px-2 py-0.5 text-body-sm font-semibold text-brand-text">{t("blocks.useCode", { code })}</p> : null}
            </div>
          );
        })}
      </Rail>
    </BlockSection>
  );
}

/** 24 tile_grid — "More reasons to shop" 2×2. */
export function TileGrid({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const tiles = items(s, "items", ctx.market.locale).slice(0, 4);
  if (tiles.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx} className="bg-surface p-4">
      <div className="grid grid-cols-2 gap-3">
        {tiles.map((tile, i) => (
          <div key={i} className="flex flex-col gap-1">
            <ImageLink item={tile} ctx={ctx} className="aspect-square w-full bg-subtle" imgClass="size-full object-contain" />
            {tile.label ? <span className="text-body font-medium">{tile.label}</span> : null}
            {tile.caption ? <span className="text-body-sm text-secondary">{tile.caption}</span> : null}
          </div>
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
