import clsx from "clsx";
import Link from "next/link";
import type { ReactNode } from "react";
import { getT } from "@/lib/i18n";
import { background, blockTitle, item, resolveLink, str, type BlockContext, type Item, type Settings } from "../settings";

/**
 * Common block chrome: title, subtitle, "View all" and background (page-builder §3 common settings).
 * Blocks sit on a white rounded card (home design) unless they set a `background`
 * or `surface: false` (e.g. inside a coloured section band).
 */
export function BlockSection({ s, ctx, children, title, className = "" }: { s: Settings; ctx: BlockContext; children: ReactNode; title?: string | null; className?: string }) {
  const t = getT(ctx.market.locale);
  const heading = title ?? blockTitle(s, ctx.market.locale);
  const subtitle = str(s, "subtitle", ctx.market.locale);
  const viewAll = resolveLink(str(s, "view_all") ?? str(s, "view_all_link"), ctx.market);
  const bg = background(s);
  const card = !bg && s.surface !== false;
  return (
    <section className={clsx("rounded-lg", bg || card ? "p-4" : "", card && "bg-surface", className)} style={bg ? { backgroundColor: bg } : undefined}>
      {heading || viewAll ? (
        <div className="mb-3 flex items-end justify-between gap-4">
          <div>
            {heading ? <h2 className="text-h3 font-semibold">{heading}</h2> : null}
            {subtitle ? <p className="text-body text-secondary">{subtitle}</p> : null}
          </div>
          {viewAll ? (
            <Link href={viewAll} className="shrink-0 rounded-sm border border-line bg-surface px-2.5 py-1 text-caption font-semibold uppercase">
              {t("common.viewAll")}
            </Link>
          ) : null}
        </div>
      ) : null}
      {children}
    </section>
  );
}

/** An image (with optional mobile crop) linking somewhere. */
export function ImageLink({ item, ctx, className = "", imgClass = "size-full object-cover", children }: { item: Item; ctx: BlockContext; className?: string; imgClass?: string; children?: ReactNode }) {
  const href = resolveLink(item.link, ctx.market);
  const body = (
    <>
      {item.image ? (
        <picture>
          {item.imageMobile ? <source media="(max-width: 767px)" srcSet={item.imageMobile} /> : null}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={item.image} alt={item.alt} loading="lazy" className={imgClass} />
        </picture>
      ) : (
        <div className="flex size-full items-center justify-center bg-brand-subtle p-4 text-center text-h4 font-semibold text-ink">{item.label}</div>
      )}
      {children}
    </>
  );
  return href ? (
    <Link href={href} className={`relative block overflow-hidden rounded-lg ${className}`}>
      {body}
    </Link>
  ) : (
    <div className={`relative overflow-hidden rounded-lg ${className}`}>{body}</div>
  );
}

/** A block element that links to `link` (market-prefixed) when it has one, else a plain div. */
export function CardLink({ link, ctx, className, children }: { link: string | null | undefined; ctx: BlockContext; className?: string; children: ReactNode }) {
  const href = resolveLink(link, ctx.market);
  return href ? (
    <Link href={href} className={className}>
      {children}
    </Link>
  ) : (
    <div className={className}>{children}</div>
  );
}

/** Card colours of a styled (text) banner: `tone` setting. */
export const CARD_TONES = {
  brand: "bg-brand text-ink",
  dark: "bg-ink text-white",
  light: "bg-warning-subtle text-ink",
  subtle: "bg-info-subtle text-ink",
  success: "bg-success-subtle text-ink",
  surface: "bg-surface text-ink",
} as const;
export type CardTone = keyof typeof CARD_TONES;

/** Sticker chip colours (`sticker_tone`, else the card tone's default). */
const STICKER_TONES = {
  dark: "bg-ink text-white",
  brand: "bg-brand text-ink",
  danger: "bg-danger text-white",
  info: "bg-link text-white",
  success: "bg-success text-white",
} as const;
const DEFAULT_STICKER: Record<CardTone, keyof typeof STICKER_TONES> = { brand: "dark", dark: "brand", light: "danger", subtle: "info", success: "success", surface: "dark" };

export function cardTone(s: Settings): CardTone {
  const tone = str(s, "tone");
  return tone && tone in CARD_TONES ? (tone as CardTone) : "light";
}

/**
 * Styled text card (home design banners): sticker chip, bold title, subtitle,
 * coupon code chip and an optional product image on a white panel.
 * Settings: `title`, `subtitle`, `sticker`, `code`, `image`, `link`,
 * `tone` (brand · dark · light · subtle · success · surface), `sticker_tone`.
 * Sizes: `hero` (tall card, image panel at the bottom), `compact` (stacked
 * card, small image panel), `banner` (wide strip, image at the end).
 */
export function StyledCard({ s, ctx, size = "hero", className }: { s: Settings; ctx: BlockContext; size?: "hero" | "compact" | "banner"; className?: string }) {
  const t = getT(ctx.market.locale);
  const card = item(s, ctx.market.locale);
  const tone = cardTone(s);
  const stickerTone = str(s, "sticker_tone");
  const sticker = STICKER_TONES[stickerTone && stickerTone in STICKER_TONES ? (stickerTone as keyof typeof STICKER_TONES) : DEFAULT_STICKER[tone]];
  const code = str(s, "code");
  const href = resolveLink(card.link, ctx.market);
  const text = (
    <div className={clsx("flex flex-col items-start", size === "banner" ? "gap-1.5" : "gap-1")}>
      {card.sticker ? <span className={clsx("rounded-xs px-1.5 py-0.5 text-caption font-semibold", sticker)}>{card.sticker}</span> : null}
      {card.label ? (
        <h3 className={clsx("font-extrabold leading-tight", size === "banner" ? "text-h3 md:text-h2" : size === "hero" ? "text-h3 md:text-h2" : "text-h4 md:text-h3")}>{card.label}</h3>
      ) : null}
      {card.caption ? <p className={clsx("text-body-sm", tone === "dark" ? "text-white/80" : "text-secondary")}>{card.caption}</p> : null}
      {code ? <span className="mt-1 rounded-xs bg-surface px-2 py-0.5 text-caption font-semibold text-ink shadow-xs">{t("blocks.code", { code })}</span> : null}
    </div>
  );
  const image = card.image ? (
    <div className={clsx("flex items-center justify-center overflow-hidden rounded-md bg-surface", size === "hero" ? "mt-auto h-40 md:h-52" : size === "compact" ? "mt-auto h-16 md:h-20" : "hidden h-full w-40 shrink-0 md:flex")}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src={card.image} alt={card.alt} loading="lazy" className="max-h-full max-w-full object-contain p-2" />
    </div>
  ) : null;
  const classes = clsx(
    "flex overflow-hidden rounded-lg p-4 md:p-5",
    CARD_TONES[tone],
    size === "banner" ? "min-h-36 flex-row items-center justify-between gap-4 md:min-h-44 md:p-6" : "h-full flex-col gap-3",
    size === "hero" && "min-h-80",
    href && "transition-shadow hover:shadow-md",
    className,
  );
  return href ? (
    <Link href={href} className={classes}>
      {text}
      {image}
    </Link>
  ) : (
    <div className={classes}>
      {text}
      {image}
    </div>
  );
}
