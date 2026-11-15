import clsx from "clsx";
import Link from "next/link";
import { Rail } from "@/components/ui/carousel";
import type { Category } from "@/lib/api/schemas";
import { items, num, resolveLink, str, type BlockContext, type Item, type Settings } from "../settings";
import { BlockSection, ImageLink } from "./shared";

/** Items from settings, or from the category tree when `source` asks for it. */
function sourced(s: Settings, ctx: BlockContext, key = "items"): Item[] {
  const manual = items(s, key, ctx.market.locale);
  if (manual.length > 0) return manual;
  const source = str(s, "source") ?? "";
  if (!source.includes("categor")) return [];
  const parent = ctx.category ? ctx.category.children : ctx.categories;
  const base = ctx.category ? `/${ctx.category.path.join("/")}` : "";
  return parent.map((c: Category) => ({ image: c.image, imageMobile: null, link: `${base}/${c.slug}`, label: c.name, caption: null, alt: c.name, sticker: null }));
}

function Tile({ tile, ctx, shape }: { tile: Item; ctx: BlockContext; shape: string }) {
  const href = resolveLink(tile.link, ctx.market);
  const round = shape === "circle" ? "rounded-full" : "rounded-lg";
  const body = (
    <div className="flex flex-col items-center gap-1.5 text-center">
      <div className={clsx("relative aspect-square w-full overflow-hidden bg-surface", round)}>
        {tile.image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={tile.image} alt={tile.alt} loading="lazy" className="size-full object-cover" />
        ) : (
          <div className="flex size-full items-center justify-center bg-brand-subtle text-h3 font-bold text-brand-text">{tile.label?.slice(0, 1)}</div>
        )}
        {tile.sticker ? <span className="absolute end-1 top-1 rounded-xs bg-danger px-1 text-caption font-bold text-white">{tile.sticker}</span> : null}
      </div>
      {tile.label ? <span className="line-clamp-2 text-body-sm font-medium">{tile.label}</span> : null}
      {tile.caption ? <span className="text-caption font-medium text-success-text">{tile.caption}</span> : null}
    </div>
  );
  return href ? <Link href={href}>{body}</Link> : body;
}

/** 10 tile_scroller — circle / rounded / card tiles in 1 or 2 rows. */
export function TileScroller({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const tiles = sourced(s, ctx);
  if (tiles.length === 0) return null;
  const shape = str(s, "shape") ?? "rounded";
  const rows = num(s, "rows", 1) >= 2 ? 2 : 1;
  const columns = rows === 2 ? chunk(tiles, 2) : tiles.map((x) => [x]);
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass="basis-[28%] sm:basis-[16%] lg:basis-[8.33%]">
        {columns.map((col, i) => (
          <div key={i} className="flex flex-col gap-3">
            {col.map((tile, j) => (
              <Tile key={j} tile={tile} ctx={ctx} shape={shape} />
            ))}
          </div>
        ))}
      </Rail>
    </BlockSection>
  );
}

/** 11 category_tiles — 12 square tiles + arrows. */
export function CategoryTiles({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  return <TileScroller s={{ shape: "rounded", ...s }} ctx={ctx} />;
}

/** 12 category_showcase — header + 7 tiles + VIEW ALL. */
export function CategoryShowcase({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const tiles = sourced(s, ctx, "tiles").slice(0, 7);
  if (tiles.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        {tiles.map((tile, i) => (
          <Tile key={i} tile={tile} ctx={ctx} shape="rounded" />
        ))}
      </div>
    </BlockSection>
  );
}

/** 13 brand_carousel — brand logos / cards with offer captions. */
export function BrandCarousel({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const brands = items(s, "items", ctx.market.locale);
  if (brands.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass="basis-[40%] sm:basis-1/4 lg:basis-1/6">
        {brands.map((brand, i) => (
          <div key={i} className="flex flex-col gap-1.5 text-center">
            <ImageLink item={brand} ctx={ctx} className="aspect-[4/3] w-full bg-surface" imgClass="size-full object-contain p-3" />
            {brand.label ? <span className="text-label font-semibold uppercase">{brand.label}</span> : null}
            {brand.caption ? <span className="text-body-sm text-success-text">{brand.caption}</span> : null}
          </div>
        ))}
      </Rail>
    </BlockSection>
  );
}

/** 14 segment_tabs — Women / Men / Kids text tabs. */
export function SegmentTabs({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const tabs = items(s, "tabs", ctx.market.locale);
  if (tabs.length === 0) return null;
  return (
    <nav className="scrollbar-none flex gap-2 overflow-x-auto">
      {tabs.map((tab, i) => {
        const href = resolveLink(tab.link ?? (str(s, "target") ? `/${str(s, "target")}` : null), ctx.market) ?? "#";
        return (
          <Link key={i} href={href} className={clsx("shrink-0 rounded-full border px-4 py-2 text-body font-medium", i === 0 ? "border-ink bg-ink text-white" : "border-line bg-surface")}>
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}

/** 15 quick_links — 6–10 icon/text chips. */
export function QuickLinks({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const links = items(s, "items", ctx.market.locale);
  if (links.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="scrollbar-none flex gap-2 overflow-x-auto">
        {links.map((link, i) => {
          const href = resolveLink(link.link, ctx.market) ?? "#";
          return (
            <Link key={i} href={href} className="flex shrink-0 items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-body font-medium">
              {link.image ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={link.image} alt="" className="size-6 object-contain" />
              ) : null}
              {link.label}
            </Link>
          );
        })}
      </div>
    </BlockSection>
  );
}

function chunk<T>(values: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < values.length; i += size) out.push(values.slice(i, i + size));
  return out;
}
