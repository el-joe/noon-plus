"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Fragment, useEffect, useRef, type ReactNode } from "react";
import { ProductCard as ProductTile } from "@/components/product/product-card";
import { Alert, Spinner } from "@/components/ui/misc";
import { getProducts, type ProductFilters } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import type { ProductCard } from "@/lib/api/schemas";
import { getSellerProducts } from "@/lib/api/sellers";
import { useErrorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

export type ListingSource = { kind: "products" } | { kind: "seller"; slug: string };

/**
 * QC-027 infinite scroll (Figma 40:4438 "Showing 60 of 1000+ · infinite
 * scroll loads more"). Page 1 (or the `?page=` the URL asked for) is rendered
 * on the server; later pages load through the BFF when the sentinel scrolls
 * into view. The "Load more" control is a real link to the next page, so the
 * list still pages without JavaScript and for crawlers.
 */
export function InfiniteProducts({
  initial,
  filters,
  source,
  page,
  last,
  total,
  nextHref,
  firstHref,
  inserts = [],
  every = 20,
  totalLabel,
}: {
  initial: ProductCard[];
  filters: ProductFilters;
  source: ListingSource;
  page: number;
  last: number;
  total: number;
  nextHref: string | null;
  firstHref: string | null;
  /** "100K+" — the results header's rounded total. */
  totalLabel?: string;
  /** Category page: sponsored brand rows placed after every `every` products (4 rows of 5), in turn. */
  inserts?: ReactNode[];
  every?: number;
}) {
  const t = useTranslations("storefront.listing");
  const tc = useTranslations("categoryPage");
  const market = useMarket();
  const message = useErrorMessage();
  const sentinel = useRef<HTMLDivElement>(null);

  const query = useInfiniteQuery({
    queryKey: ["listing", market.slug, source, filters, page],
    initialPageParam: page + 1,
    enabled: false,
    queryFn: async ({ pageParam }) => {
      const next = { ...filters, page: pageParam };
      const result = source.kind === "seller" ? await getSellerProducts(market.slug, source.slug, next) : await getProducts(market.slug, next);
      return { products: result.data, page: Number(result.meta.page ?? pageParam), last: Number(result.meta.last_page ?? pageParam) };
    },
    getNextPageParam: (lastPage) => (lastPage.page < lastPage.last ? lastPage.page + 1 : undefined),
  });

  const loaded = query.data?.pages ?? [];
  const lastLoaded = loaded.at(-1);
  const hasMore = lastLoaded ? lastLoaded.page < lastLoaded.last : page < last;
  const { fetchNextPage, isFetching, isError } = query;

  const loadMore = () => {
    if (isFetching || !hasMore) return;
    if (loaded.length === 0) void query.refetch();
    else void fetchNextPage();
  };
  const loadRef = useRef(loadMore);
  loadRef.current = loadMore;

  useEffect(() => {
    const node = sentinel.current;
    if (!node || !hasMore || isError || typeof IntersectionObserver === "undefined") return;
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) loadRef.current();
    }, { rootMargin: "600px 0px" });
    observer.observe(node);
    return () => observer.disconnect();
  }, [hasMore, isError, loaded.length]);

  const products = [...initial, ...loaded.flatMap((p) => p.products)];
  const perPage = filters.per_page ?? 30;
  const shown = Math.min(total, (page - 1) * perPage + products.length);

  return (
    <div className="flex flex-col gap-4">
      {firstHref ? (
        <Link href={firstHref} className="self-center text-body-sm text-link">
          {t("backToFirst")}
        </Link>
      ) : null}
      {chunks(products, every).map((chunk, index, all) => (
        <Fragment key={index}>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {chunk.map((product, i) => (
              <ProductTile key={`${product.slug}-${index}-${i}`} product={cardFromApi(product)} />
            ))}
          </div>
          {inserts.length > 0 && chunk.length === every && (index < all.length - 1 || hasMore) ? inserts[index % inserts.length] : null}
        </Fragment>
      ))}
      <div ref={sentinel} className="flex flex-col items-center gap-3 py-2">
        {isError ? <Alert tone="danger">{message(query.error)}</Alert> : null}
        <p className="text-body-sm text-secondary" aria-live="polite">
          {t("showing", { shown: shown.toLocaleString(market.locale), total: totalLabel ?? total.toLocaleString(market.locale) })}
          {hasMore ? <span className="text-muted"> · {tc("infinite")}</span> : null}
        </p>
        {isFetching ? <Spinner className="size-5" /> : null}
        {hasMore && !isFetching && nextHref ? (
          <a
            href={nextHref}
            onClick={(event) => {
              event.preventDefault();
              loadMore();
            }}
            className="rounded-md border border-line-strong bg-surface px-5 py-2 text-body font-semibold hover:bg-subtle"
          >
            {t("loadMore")}
          </a>
        ) : null}
      </div>
    </div>
  );
}

function chunks<T>(items: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
}
