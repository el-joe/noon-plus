"use client";

import clsx from "clsx";
import { Check, Heart, Plus } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import * as api from "@/lib/api/endpoints";
import type { Money } from "@/lib/api/schemas";
import { discountPercent, formatAmount } from "@/lib/format";
import { useCartMutation, useWishlistToggle } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { AdLabel, ProductImage } from "../ui/misc";
import { RatingBadge } from "../ui/rating";

export type ProductCardData = {
  slug: string | null;
  title: string | null;
  brand?: string | null;
  price: Money | null;
  wasPrice?: Money | null;
  listingId?: number | null;
  sponsored?: boolean;
  label?: string | null;
  adToken?: string | null;
  badge?: string | null;
  claimedPercent?: number | null;
  soldOut?: boolean;
  /** Main image URL (G-W1); the placeholder shows when null. */
  image?: string | null;
  imageAlt?: string | null;
  rating?: { average: string | number | null; count: number } | null;
  /** Show the wishlist heart (default true when the card has a slug). */
  wishlist?: boolean;
};

/** Record a sponsored click (CPC) without delaying navigation. */
export function trackAdClick(market: string, token: string | null | undefined) {
  if (!token) return;
  void api.recordAdClick(market, token).catch(() => undefined);
}

/** Product card (Figma 16:119): Default / Sponsored ("Ad") / Deal variants. */
export function ProductCard({ product, compact = false }: { product: ProductCardData; compact?: boolean }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const add = useCartMutation((m, listingId: number) => api.setCartItem(m, listingId, 1));
  const percent = discountPercent(product.price, product.wasPrice);
  const link = product.slug ? href(`/products/${product.slug}`) : href("/");
  const title = product.title ?? "";

  return (
    <article className={clsx("group relative flex h-full flex-col overflow-hidden rounded-md bg-surface pb-3", !compact && "shadow-xs")}>
      <Link href={link} onClick={() => product.sponsored && trackAdClick(market.slug, product.adToken)} className="flex flex-1 flex-col gap-2">
        <div className="relative aspect-square w-full overflow-hidden">
          <ProductImage src={product.image} alt={product.imageAlt || title} />
          {product.badge ? <span className="absolute start-2 top-2 rounded-xs bg-ink px-1.5 py-0.5 text-caption font-medium text-white">{product.badge}</span> : null}
          {product.sponsored ? <AdLabel className="absolute end-2 top-2 bg-surface/90">{product.label || t("common.ad")}</AdLabel> : null}
        </div>
        <div className="flex flex-col gap-1.5 px-2.5">
          {product.sponsored ? <span className="text-caption font-medium text-muted">{t("common.sponsored")}</span> : null}
          {product.brand ? <span className="text-caption font-medium uppercase text-secondary">{product.brand}</span> : null}
          <h3 className="line-clamp-2 h-10 text-body text-ink">{title}</h3>
          {product.rating ? <RatingBadge average={product.rating.average} count={product.rating.count} /> : null}
          {product.price ? (
            <p className="flex items-baseline gap-1 text-ink">
              <span className="text-caption font-medium">{product.price.currency}</span>
              <span className="text-body-lg font-bold">{formatAmount(product.price.amount)}</span>
            </p>
          ) : null}
          {percent && product.wasPrice ? (
            <p className="flex items-center gap-1.5 text-body-sm">
              <span className="text-muted line-through">{formatAmount(product.wasPrice.amount)}</span>
              <span className="text-label font-medium text-success-text">{t("product.off", { percent })}</span>
            </p>
          ) : null}
          {product.claimedPercent != null ? (
            <div className="flex flex-col gap-1">
              <div className="h-1.5 overflow-hidden rounded-full bg-subtle">
                <div className="h-full rounded-full bg-danger" style={{ width: `${Math.min(100, product.claimedPercent)}%` }} />
              </div>
              <span className="text-caption font-medium text-danger-text">{product.soldOut ? t("blocks.soldOut") : t("blocks.claimed", { percent: product.claimedPercent })}</span>
            </div>
          ) : null}
        </div>
      </Link>
      {product.slug && product.wishlist !== false ? <WishlistHeart slug={product.slug} sponsored={!!product.sponsored} /> : null}
      {product.listingId && !product.soldOut ? (
        <QuickAdd pending={add.isPending} done={add.isSuccess} onAdd={() => add.mutate(product.listingId!)} label={t("product.addToCart")} />
      ) : null}
    </article>
  );
}

function QuickAdd({ pending, done, onAdd, label }: { pending: boolean; done: boolean; onAdd: () => void; label: string }) {
  return (
    <div className="pointer-events-none absolute inset-x-0 top-0 aspect-square">
      <button
        type="button"
        onClick={onAdd}
        disabled={pending}
        aria-label={label}
        className="pointer-events-auto absolute bottom-2 end-2 flex size-9 items-center justify-center rounded-md border border-line bg-surface shadow-sm hover:bg-subtle disabled:opacity-60"
      >
        {done ? <Check className="size-[18px] text-success-text" /> : <Plus className="size-[18px]" />}
      </button>
    </div>
  );
}

/** Card / PDP heart (G-W6). Guests go to sign-in. */
export function WishlistButton({ slug, className, withLabel = false }: { slug: string; className?: string; withLabel?: boolean }) {
  const t = useTranslations("wishlist");
  const { saved, toggle, pending } = useWishlistToggle(slug);
  return (
    <button
      type="button"
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        toggle();
      }}
      disabled={pending}
      aria-pressed={saved}
      aria-label={saved ? t("remove") : t("add")}
      className={clsx("flex items-center justify-center gap-1.5 disabled:opacity-60", className)}
    >
      <Heart className={clsx("size-[18px]", saved ? "fill-danger text-danger" : "text-ink")} />
      {withLabel ? <span className="text-body font-medium">{saved ? t("saved") : t("save")}</span> : null}
    </button>
  );
}

function WishlistHeart({ slug, sponsored }: { slug: string; sponsored: boolean }) {
  return (
    <WishlistButton
      slug={slug}
      className={clsx("absolute end-2 size-8 rounded-full border border-line bg-surface/95 shadow-xs hover:bg-subtle", sponsored ? "top-9" : "top-2")}
    />
  );
}
