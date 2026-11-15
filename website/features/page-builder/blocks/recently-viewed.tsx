"use client";

import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import { Rail } from "@/components/ui/carousel";
import { useLocalStorage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

export const recentKey = (country: string) => `nawy.recent.${country}`;

/** Recently viewed products, kept on this device by the product page. */
export function RecentlyViewed({ title }: { title: string }) {
  const market = useMarket();
  const [recent] = useLocalStorage<ProductCardData[]>(recentKey(market.country.code), []);
  if (recent.length === 0) return null;
  return (
    <section>
      <h2 className="mb-3 text-h3 font-semibold">{title}</h2>
      <Rail itemClass="basis-[46%] sm:basis-1/3 md:basis-1/4 lg:basis-1/6">
        {recent.map((p, i) => (
          <ProductCard key={i} product={p} />
        ))}
      </Rail>
    </section>
  );
}
