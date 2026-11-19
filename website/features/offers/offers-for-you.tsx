"use client";

import { useQuery } from "@tanstack/react-query";
import { Zap } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Countdown } from "@/features/page-builder/countdown";
import { getOffers } from "@/lib/api/discovery";
import { useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { CouponChip } from "./coupon-chip";

/**
 * Home "Offers for you" (QC-064): the public coupons this shopper can still
 * use (copy / apply) and the live or upcoming flash sales. Read through the
 * BFF so a signed-in customer gets their own list; hidden when empty.
 */
export function OffersForYou() {
  const t = useTranslations("discovery.offers");
  const market = useMarket();
  const href = useHref();
  const { isSignedIn, isLoading } = useSession();
  const { data } = useQuery({
    queryKey: ["offers", market.slug, isSignedIn],
    enabled: !isLoading,
    staleTime: 5 * 60_000,
    queryFn: async () => (await getOffers(market.slug)).data,
  });
  if (!data || (data.coupons.length === 0 && data.flash_sales.length === 0)) return null;

  return (
    <section aria-labelledby="offers-for-you" className="flex flex-col gap-3">
      <h2 id="offers-for-you" className="text-h3 font-semibold">
        {t("title")}
      </h2>
      {data.flash_sales.length > 0 ? (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.flash_sales.map((sale) => (
            <li key={sale.id}>
              <Link href={href(`/flash-sale/${sale.id}`)} className="flex h-full flex-col gap-2 rounded-lg bg-ink p-4 text-white hover:opacity-95">
                <span className="flex items-center gap-1.5 text-caption font-semibold uppercase text-brand">
                  <Zap className="size-4" />
                  {sale.state === "live" ? t("liveNow") : t("upcoming")}
                </span>
                <span className="text-h4 font-bold">{sale.name}</span>
                <span className="flex flex-wrap items-center gap-2 text-body-sm text-white/80">
                  {t("deals", { count: sale.deals_count })} ·{" "}
                  {sale.state === "live" ? (
                    <span className="flex items-center gap-1">
                      {t("endsIn")} <Countdown endsAt={sale.ends_at} tone="danger" />
                    </span>
                  ) : (
                    <span className="flex items-center gap-1">
                      {t("startsIn")} <Countdown endsAt={sale.starts_at} tone="danger" />
                    </span>
                  )}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
      {data.coupons.length > 0 ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {data.coupons.slice(0, 8).map((coupon) => (
            <CouponChip key={coupon.code} coupon={coupon} className="bg-surface" />
          ))}
        </div>
      ) : null}
    </section>
  );
}
