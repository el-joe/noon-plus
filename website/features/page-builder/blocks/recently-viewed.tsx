"use client";

import { useTranslations } from "next-intl";
import { ProductCard } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { useRecentlyViewed } from "@/features/search/recently-viewed";

export { recentKey } from "@/features/search/recently-viewed";

/**
 * Recently viewed products (QC-043): this device for guests, the account's
 * synced list (`me/recently-viewed`) once signed in. "Clear" empties both.
 */
export function RecentlyViewed({ title }: { title: string }) {
  const t = useTranslations("discovery.recent");
  const { items, clear } = useRecentlyViewed();
  if (items.length === 0) return null;
  return (
    <section>
      <div className="mb-3 flex items-center justify-between gap-3">
        <h2 className="text-h3 font-semibold">{title}</h2>
        <button type="button" onClick={() => clear()} className="text-body-sm text-link hover:underline">
          {t("clear")}
        </button>
      </div>
      <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
        {items.map((p) => (
          <ProductCard key={p.slug ?? p.title} product={p} />
        ))}
      </Rail>
    </section>
  );
}
