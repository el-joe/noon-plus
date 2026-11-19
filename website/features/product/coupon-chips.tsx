"use client";

import { useQuery } from "@tanstack/react-query";
import { CouponChip } from "@/features/offers/coupon-chip";
import { getProductCoupons } from "@/lib/api/discovery";
import { useMarket } from "@/lib/market-context";

/** PDP coupon chips (Figma 24:2, QC-057): public coupons whose targets cover this product. */
export function CouponChips({ slug }: { slug: string }) {
  const market = useMarket();
  const { data } = useQuery({
    queryKey: ["product-coupons", market.slug, slug],
    staleTime: 5 * 60_000,
    queryFn: async () => (await getProductCoupons(market.slug, slug)).data,
  });
  if (!data || data.length === 0) return null;
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {data.slice(0, 4).map((coupon) => (
        <CouponChip key={coupon.code} coupon={coupon} />
      ))}
    </div>
  );
}
