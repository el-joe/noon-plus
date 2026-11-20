"use client";

import { useTranslations } from "next-intl";
import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { useRecentlyViewed } from "@/features/search/recently-viewed";

const ITEM = "basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6";

/** PDP "Similar products" carousel (same leaf category, this product left out). */
export function SimilarProductsRail({ title, products }: { title: string; products: ProductCardData[] }) {
  if (products.length === 0) return null;
  return (
    <section className="flex flex-col gap-3" aria-label={title}>
      <h2 className="text-h3 font-semibold">{title}</h2>
      <Rail itemClass={ITEM}>
        {products.map((p) => (
          <ProductCard key={p.slug ?? p.title} product={p} />
        ))}
      </Rail>
    </section>
  );
}

/** PDP "Recently viewed" carousel: this device / account list without the product on screen. */
export function RecentlyViewedRail({ title, exclude }: { title: string; exclude: string }) {
  const t = useTranslations("discovery.recent");
  const { items, clear } = useRecentlyViewed();
  const list = items.filter((p) => p.slug !== exclude);
  if (list.length === 0) return null;
  return (
    <section className="flex flex-col gap-3" aria-label={title}>
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-h3 font-semibold">{title}</h2>
        <button type="button" onClick={() => clear()} className="text-body-sm text-link hover:underline">
          {t("clear")}
        </button>
      </div>
      <Rail itemClass={ITEM}>
        {list.map((p) => (
          <ProductCard key={p.slug ?? p.title} product={p} />
        ))}
      </Rail>
    </section>
  );
}
