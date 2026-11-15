import { BadgeCheck, CalendarDays, Megaphone, Star } from "lucide-react";
import Link from "next/link";
import { buttonClass } from "@/components/ui/button";
import { Badge, EmptyState } from "@/components/ui/misc";
import type { ShopPage } from "@/lib/api/classifieds";
import { formatDay } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { AdGrid } from "./ad-card";
import { loadClassifiedCategories } from "./data";
import { FollowButton } from "./follow-button";

/** `/classified/shop/[slug]` — Open Souq seller shop (Figma 57:2192). */
export async function ClassifiedShop({ market, data, page, lastPage }: { market: Market; data: ShopPage; page: number; lastPage: number }) {
  const t = getT(market.locale);
  const { propertySlugs } = await loadClassifiedCategories(market.slug);
  const { shop, ads } = data;
  const name = shop.name ?? shop.slug;
  const base = `/${market.slug}/classified/shop/${encodeURIComponent(shop.slug)}`;

  return (
    <div className="flex flex-col gap-6">
      <section className="overflow-hidden rounded-xl border border-line bg-surface">
        <div className="h-28 bg-ink md:h-36" />
        <div className="flex flex-wrap items-end gap-4 px-5 pb-5">
          <span className="-mt-10 flex size-20 items-center justify-center rounded-xl border-4 border-surface bg-brand text-h2 font-bold">{name.slice(0, 2).toUpperCase()}</span>
          <div className="flex min-w-0 flex-1 flex-col gap-1 pt-3">
            <h1 className="flex items-center gap-2 text-h2 font-bold">
              {name}
              {shop.verified ? <BadgeCheck className="size-5 text-link" aria-label={t("classifieds.verifiedBusiness")} /> : null}
            </h1>
            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-body-sm text-secondary">
              {shop.rating !== null && Number(shop.rating) > 0 ? (
                <span className="flex items-center gap-1">
                  <Star className="size-3.5 fill-brand text-brand" />
                  {Number(shop.rating).toFixed(1)}
                </span>
              ) : null}
              <span className="flex items-center gap-1">
                <Megaphone className="size-3.5" />
                {t("classifieds.shop.liveAds", { count: shop.live_ads })}
              </span>
              {shop.member_since ? (
                <span className="flex items-center gap-1">
                  <CalendarDays className="size-3.5" />
                  {t("classifieds.shop.memberSince", { date: formatDay(shop.member_since, market.locale) })}
                </span>
              ) : null}
              {shop.verified ? <Badge tone="info">{t("classifieds.verifiedBusiness")}</Badge> : null}
            </p>
          </div>
          <FollowButton slug={shop.slug} />
        </div>
      </section>

      <section className="flex flex-col gap-4">
        <h2 className="text-h3 font-semibold">{t("classifieds.shop.ads")}</h2>
        {ads.length === 0 ? <EmptyState title={t("classifieds.shop.empty")} /> : <AdGrid ads={ads} propertySlugs={propertySlugs} />}
        {lastPage > 1 ? (
          <nav className="flex items-center justify-center gap-2" aria-label={t("classifieds.pagination")}>
            {page > 1 ? (
              <Link href={`${base}?page=${page - 1}`} className={buttonClass("outline", "sm")}>
                {t("common.back")}
              </Link>
            ) : null}
            <span className="text-body-sm text-secondary">{t("classifieds.pageOf", { page, last: lastPage })}</span>
            {page < lastPage ? (
              <Link href={`${base}?page=${page + 1}`} className={buttonClass("outline", "sm")}>
                {t("common.continue")}
              </Link>
            ) : null}
          </nav>
        ) : null}
      </section>
    </div>
  );
}
