"use client";

import { useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Star, Store } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Alert, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/support";
import { formatDay } from "@/lib/format";
import { useErrorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/** `/account/followed-stores` — product stores the customer follows (QC-008). */
export function FollowedStores() {
  const t = useTranslations("accountPlus.followed");
  const market = useMarket();
  const href = useHref();
  const client = useQueryClient();
  const errorText = useErrorMessage();
  const key = ["followed-sellers", market.slug];
  const list = useInfiniteQuery({
    queryKey: key,
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => api.getFollowedSellers(market.slug, pageParam),
    getNextPageParam: (last) => (Number(last.meta.page ?? 1) < Number(last.meta.last_page ?? 1) ? Number(last.meta.page ?? 1) + 1 : undefined),
  });
  const unfollow = useMutation({
    mutationFn: (slug: string) => api.unfollowSeller(market.slug, slug),
    onSuccess: () => client.invalidateQueries({ queryKey: key }),
  });
  const stores = list.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-h2 font-bold">{t("title")}</h1>
      {list.isLoading ? <Skeleton className="h-48 w-full" /> : null}
      {list.error || unfollow.error ? <Alert tone="danger">{errorText(list.error ?? unfollow.error)}</Alert> : null}
      {list.data && stores.length === 0 ? <EmptyState icon={<Store className="size-10" />} title={t("empty")} body={t("emptyHint")} /> : null}
      <ul className="grid gap-3 sm:grid-cols-2">
        {stores.map((store) => {
          const average = store.rating?.average != null ? Number(store.rating.average) : null;
          return (
            <li key={store.slug}>
              <Card className="flex items-center gap-3">
                <Link href={href(`/seller/${encodeURIComponent(store.slug)}`)} className="flex min-w-0 flex-1 items-center gap-3">
                  <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-line bg-subtle">
                    {store.logo ? <img src={store.logo} alt="" className="size-full object-cover" /> : <Store className="size-6 text-muted" />}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-body font-semibold">{store.name}</span>
                    <span className="flex items-center gap-1 text-body-sm text-secondary">
                      {average !== null && store.rating?.count ? (
                        <>
                          <Star className="size-3.5 fill-[var(--color-brand)] text-brand" />
                          {average.toFixed(1)} · {t("ratings", { count: store.rating.count })}
                        </>
                      ) : (
                        t("noRatings")
                      )}
                    </span>
                    {store.followed_at ? <span className="block text-caption text-muted">{t("since", { date: formatDay(store.followed_at, market.locale) })}</span> : null}
                  </span>
                </Link>
                <Button size="sm" variant="outline" onClick={() => unfollow.mutate(store.slug)} disabled={unfollow.isPending && unfollow.variables === store.slug}>
                  {t("unfollow")}
                </Button>
              </Card>
            </li>
          );
        })}
      </ul>
      {list.hasNextPage ? (
        <div className="flex justify-center">
          <Button variant="outline" onClick={() => list.fetchNextPage()} disabled={list.isFetchingNextPage}>
            {t("loadMore")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
