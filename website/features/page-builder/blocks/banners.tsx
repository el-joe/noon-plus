import clsx from "clsx";
import Link from "next/link";
import { Suspense } from "react";
import { getT } from "@/lib/i18n";
import { Countdown } from "../countdown";
import { item, items, list, num, resolveLink, str, type BlockContext, type Settings } from "../settings";
import { AdPlacements } from "./ads";
import { BlockSection, ImageLink } from "./shared";

/** 5 promo_banner — single, 2-up, 1 big + 2×2, stacked 2, side panel. */
export function PromoBanner({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const banners = items(s, "items", ctx.market.locale);
  if (banners.length === 0) return null;
  const layout = str(s, "layout") ?? str(s, "variant") ?? (banners.length >= 5 ? "big_2x2" : banners.length === 1 ? "single" : "n_up");
  return (
    <BlockSection s={s} ctx={ctx}>
      {layout === "big_2x2" || layout === "1_big_2x2" ? (
        <div className="grid gap-3 md:grid-cols-2">
          <ImageLink item={banners[0]} ctx={ctx} className="aspect-square w-full" />
          <div className="grid grid-cols-2 gap-3">
            {banners.slice(1, 5).map((b, i) => (
              <ImageLink key={i} item={b} ctx={ctx} className="aspect-square w-full" />
            ))}
          </div>
        </div>
      ) : layout === "stacked" ? (
        <div className="grid gap-3">
          {banners.slice(0, 2).map((b, i) => (
            <ImageLink key={i} item={b} ctx={ctx} className="aspect-[4/1] w-full" />
          ))}
        </div>
      ) : (
        <div className={clsx("grid gap-3", banners.length > 1 && "md:grid-cols-2", banners.length > 2 && "lg:grid-cols-3")}>
          {banners.map((b, i) => (
            <ImageLink key={i} item={b} ctx={ctx} className={banners.length === 1 ? "aspect-[4/1] w-full" : "aspect-[2/1] w-full"} />
          ))}
        </div>
      )}
    </BlockSection>
  );
}

const N_UP: Record<number, string> = {
  2: "grid-cols-2",
  3: "grid-cols-2 md:grid-cols-3",
  4: "grid-cols-2 md:grid-cols-4",
  5: "grid-cols-2 md:grid-cols-5",
  6: "grid-cols-3 md:grid-cols-6",
};

/** 6 banner_grid — n_up, showcase_1x7, brand_tiles, quick_links, segment_tabs, wide, two_by_two. */
export function BannerGrid({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const tiles = items(s, "tiles", ctx.market.locale);
  if (tiles.length === 0) return null;
  const variant = str(s, "variant") ?? str(s, "layout") ?? "n_up";
  const header = s.header && typeof s.header === "object" ? item(s.header as Settings, ctx.market.locale) : null;
  const cols =
    variant === "wide" ? "grid-cols-1" :
    variant === "two_by_two" ? "grid-cols-2" :
    variant === "quick_links" || variant === "brand_tiles" ? "grid-cols-3 sm:grid-cols-5 lg:grid-cols-10" :
    variant === "showcase_1x7" ? "grid-cols-3 sm:grid-cols-4 lg:grid-cols-7" :
    N_UP[Math.min(6, Math.max(2, num(s, "columns", tiles.length)))];
  const small = variant === "quick_links" || variant === "brand_tiles";
  return (
    <BlockSection s={s} ctx={ctx} title={header?.label ?? undefined}>
      <div className={clsx("grid gap-3", cols)}>
        {tiles.map((tile, i) => (
          <div key={i} className="flex flex-col gap-1.5">
            <ImageLink item={tile} ctx={ctx} className={clsx("w-full bg-surface", variant === "wide" ? "aspect-[5/1]" : small ? "aspect-square" : "aspect-[4/5]")} imgClass={small ? "size-full object-contain p-2" : "size-full object-cover"} />
            {tile.label && tile.image ? <span className="text-center text-body font-medium">{tile.label}</span> : null}
            {tile.caption ? <span className="text-center text-body-sm text-success-text">{tile.caption}</span> : null}
          </div>
        ))}
      </div>
    </BlockSection>
  );
}

/** 7 banner_strip — full-width image with optional hotspots (several links on one image). */
export function BannerStrip({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const strip = item(s, ctx.market.locale);
  if (!strip.image) return null;
  const hotspots = list(s, "hotspots");
  const height = num(s, "height", 0);
  return (
    <div className="relative">
      <ImageLink item={hotspots.length ? { ...strip, link: null } : strip} ctx={ctx} className="w-full" imgClass={clsx("w-full object-cover", height ? "" : "h-auto")} />
      {hotspots.map((spot, i) => {
        const href = resolveLink(str(spot, "link"), ctx.market);
        if (!href) return null;
        return (
          <Link
            key={i}
            href={href}
            aria-label={str(spot, "label", ctx.market.locale) ?? `${i + 1}`}
            className="absolute"
            style={{ left: `${num(spot, "x", 0)}%`, top: `${num(spot, "y", 0)}%`, width: `${num(spot, "w", 10)}%`, height: `${num(spot, "h", 100)}%` }}
          />
        );
      })}
    </div>
  );
}

/** 8 banner_full — organic image, or a sponsored ad slot (always labelled). */
export function BannerFull({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const slot = str(s, "ad_slot_key");
  if (slot) {
    return (
      <Suspense fallback={null}>
        <AdPlacements placement={slot} ctx={ctx} variant="banner" />
      </Suspense>
    );
  }
  const banner = item(s, ctx.market.locale);
  if (!banner.image) return null;
  return <ImageLink item={banner} ctx={ctx} className="aspect-[5/1] w-full" />;
}

/** 9 countdown_strip — thin campaign bar with a live countdown. */
export function CountdownStrip({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const text = str(s, "text", ctx.market.locale);
  const endsAt = str(s, "ends_at");
  if (!text || !endsAt) return null;
  const href = resolveLink(str(s, "link"), ctx.market);
  const t = getT(ctx.market.locale);
  const body = (
    <div className="flex flex-wrap items-center justify-center gap-3 rounded-md bg-ink px-4 py-2 text-white">
      <span className="text-body font-semibold">{text}</span>
      <span className="text-body-sm opacity-80">{t("blocks.endsIn")}</span>
      <Countdown endsAt={endsAt} />
    </div>
  );
  return href ? <Link href={href}>{body}</Link> : body;
}
