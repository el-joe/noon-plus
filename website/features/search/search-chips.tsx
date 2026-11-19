"use client";

import { useQuery } from "@tanstack/react-query";
import { Flame, Search, TrendingUp } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { getPopularSearches, getTrendingSearches, type TrendingSearch } from "@/lib/api/discovery";
import { useHref, useMarket } from "@/lib/market-context";

export function usePopularSearches(enabled = true) {
  const market = useMarket();
  return useQuery({
    queryKey: ["search-popular", market.slug],
    enabled,
    staleTime: 10 * 60_000,
    queryFn: async () => (await getPopularSearches(market.slug)).data,
  });
}

export function useTrendingSearches(enabled = true) {
  const market = useMarket();
  return useQuery({
    queryKey: ["search-trending", market.slug],
    enabled,
    staleTime: 10 * 60_000,
    queryFn: async () => (await getTrendingSearches(market.slug)).data,
  });
}

/** Where a trending chip goes: a search, or the category's listing page. */
export function trendingHref(href: (path: string) => string, item: TrendingSearch): string {
  if (item.type === "category" && item.category) return href(`/${encodeURIComponent(item.category)}`);
  return `${href("/search")}?q=${encodeURIComponent(item.query ?? item.label)}`;
}

const chip = "inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-body-sm hover:border-line-strong hover:bg-subtle";

/**
 * Popular and trending searches as chips (QC-043, G-A1). Used in the header
 * suggest dropdown and on the search page (empty query / no results).
 */
export function SearchChips({ onPick, className }: { onPick?: () => void; className?: string }) {
  const t = useTranslations("discovery.search");
  const href = useHref();
  const popular = usePopularSearches();
  const trending = useTrendingSearches();
  const popularItems = popular.data ?? [];
  const trendingItems = trending.data ?? [];
  if (popularItems.length === 0 && trendingItems.length === 0) return null;
  return (
    <div className={className ?? "flex flex-col gap-4"}>
      {trendingItems.length > 0 ? (
        <section>
          <h2 className="mb-2 flex items-center gap-1.5 text-body-sm font-semibold">
            <TrendingUp className="size-4 text-danger-text" />
            {t("trending")}
          </h2>
          <ul className="flex flex-wrap gap-2">
            {trendingItems.map((item) => (
              <li key={`${item.type}-${item.category ?? item.query ?? item.label}`}>
                <Link href={trendingHref(href, item)} onClick={onPick} className={chip}>
                  {item.type === "category" ? <Flame className="size-3.5 text-danger-text" /> : <Search className="size-3.5 text-muted" />}
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      {popularItems.length > 0 ? (
        <section>
          <h2 className="mb-2 text-body-sm font-semibold">{t("popular")}</h2>
          <ul className="flex flex-wrap gap-2">
            {popularItems.map((item) => (
              <li key={item.query}>
                <Link href={`${href("/search")}?q=${encodeURIComponent(item.query)}`} onClick={onPick} className={chip}>
                  <Search className="size-3.5 text-muted" />
                  {item.query}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
