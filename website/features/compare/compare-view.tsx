"use client";

import { useQuery } from "@tanstack/react-query";
import { GitCompareArrows, Plus, ShoppingCart, X } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, type ReactNode } from "react";
import { MerchBadge } from "@/components/product/product-card";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { RatingBadge } from "@/components/ui/rating";
import { getComparison, type CompareProduct } from "@/lib/api/discovery";
import * as api from "@/lib/api/endpoints";
import { formatMoney } from "@/lib/format";
import { useCartMutation, useErrorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { COMPARE_MAX, compareHref, useCompareList } from "./compare-store";

function optionRows(products: CompareProduct[]): { label: string; values: string[] }[] {
  const keys = new Set<string>();
  for (const p of products) if (p.options && !Array.isArray(p.options)) Object.keys(p.options).forEach((k) => keys.add(k));
  return [...keys].map((k) => ({
    label: k,
    values: products.map((p) => (p.options && !Array.isArray(p.options) ? (p.options[k] ?? []).join(", ") : "")),
  }));
}

/** `/compare?slugs=a,b[,c,d]` (FR-CAT-6, QC-043): 2–4 products side by side. */
export function CompareView({ slugs }: { slugs: string[] }) {
  const t = useTranslations("discovery.compare");
  const tp = useTranslations("product");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const list = useCompareList();
  const message = useErrorMessage();
  const valid = slugs.length >= 2 && slugs.length <= COMPARE_MAX;
  const { data, isLoading, error } = useQuery({
    queryKey: ["compare", market.slug, slugs.join(",")],
    enabled: valid,
    queryFn: async () => (await getComparison(market.slug, slugs)).data,
  });

  // Keep the device list in step with what is on screen.
  const { items, add } = list;
  useEffect(() => {
    if (!data) return;
    for (const p of data.products) {
      if (!items.some((i) => i.slug === p.slug) && items.length < COMPARE_MAX) add({ slug: p.slug, title: p.title, image: p.image?.url ?? null });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const remove = (slug: string) => {
    list.remove(slug);
    const rest = slugs.filter((s) => s !== slug);
    router.replace(rest.length > 0 ? compareHref(href, rest) : href("/compare"));
  };

  if (!valid) {
    const picked = list.items.map((i) => i.slug);
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        <EmptyState
          icon={<GitCompareArrows className="size-10" />}
          title={t("emptyTitle")}
          body={t("emptyBody", { max: COMPARE_MAX })}
          action={
            picked.length >= 2 ? (
              <ButtonLink href={compareHref(href, picked)}>{t("compareNow")}</ButtonLink>
            ) : (
              <ButtonLink href={href("/search?q=")} variant="outline">
                {t("browse")}
              </ButtonLink>
            )
          }
        />
      </div>
    );
  }

  if (isLoading) return <Skeleton className="h-[520px] w-full" />;
  if (error || !data) return <Alert tone="danger">{message(error)}</Alert>;

  const products = data.products;
  const cols = { gridTemplateColumns: `minmax(120px,180px) repeat(${products.length}, minmax(180px, 1fr))` };
  const rows: { label: string; values: ReactNode[] }[] = [
    { label: t("price"), values: products.map((p) => <span key={p.slug} className="text-body-lg font-bold">{formatMoney(p.price)}</span>) },
    { label: t("rating"), values: products.map((p) => (p.rating && p.rating.count > 0 ? <RatingBadge key={p.slug} average={p.rating.average} count={p.rating.count} /> : <span key={p.slug} className="text-muted">—</span>)) },
    { label: t("availability"), values: products.map((p) => <span key={p.slug} className={p.in_stock ? "text-success-text" : "text-danger-text"}>{p.in_stock ? t("inStock") : tp("outOfStock")}</span>) },
    { label: tp("soldBy"), values: products.map((p) => p.seller ?? "—") },
    { label: t("brand"), values: products.map((p) => p.brand ?? "—") },
    { label: t("modelNumber"), values: products.map((p) => p.model_number ?? "—") },
    ...optionRows(products).map((r) => ({ label: r.label, values: r.values.map((v) => v || "—") })),
    ...data.specifications.map((s) => ({ label: s.label.replace(/_/g, " "), values: s.values.map((v) => (v === null || v === "" ? "—" : String(v))) })),
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("title")}</h1>
        {products.length < COMPARE_MAX ? (
          <Link href={href("/search?q=")} className="flex items-center gap-1 text-body-sm font-medium text-link">
            <Plus className="size-4" /> {t("addMore")}
          </Link>
        ) : null}
      </div>
      <div className="overflow-x-auto rounded-lg border border-line bg-surface">
        <div className="grid min-w-max" style={cols} role="table" aria-label={t("title")}>
          <div role="row" className="contents">
            <div role="columnheader" className="border-b border-line p-3" />
            {products.map((p) => (
              <div key={p.slug} role="columnheader" className="relative flex flex-col gap-2 border-b border-s border-line p-3">
                <button type="button" onClick={() => remove(p.slug)} className="absolute end-2 top-2 rounded-full bg-surface p-1 text-muted hover:text-ink" aria-label={t("remove", { title: p.title })}>
                  <X className="size-4" />
                </button>
                <Link href={href(`/products/${encodeURIComponent(p.slug)}`)} className="flex flex-col gap-2">
                  <span className="mx-auto block size-32 overflow-hidden rounded-md">
                    <ProductImage src={p.image?.url} alt={p.image?.alt || p.title} />
                  </span>
                  <span className="line-clamp-2 text-body font-medium hover:text-link">{p.title}</span>
                </Link>
                {p.badges && p.badges.length > 0 ? (
                  <span className="flex flex-wrap gap-1">
                    {p.badges.map((b) => (
                      <MerchBadge key={b.key} badge={b} />
                    ))}
                  </span>
                ) : null}
                <CompareAdd listingId={p.listing_id ?? null} inStock={!!p.in_stock} />
              </div>
            ))}
          </div>
          {rows.map((row, i) => (
            <div key={`${row.label}-${i}`} role="row" className="contents">
              <div role="rowheader" className={`p-3 text-body-sm capitalize text-secondary ${i % 2 === 0 ? "bg-subtle" : ""}`}>
                {row.label}
              </div>
              {row.values.map((v, j) => (
                <div key={j} role="cell" className={`border-s border-line p-3 text-body-sm ${i % 2 === 0 ? "bg-subtle" : ""}`}>
                  {v}
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function CompareAdd({ listingId, inStock }: { listingId: number | null; inStock: boolean }) {
  const tp = useTranslations("product");
  const message = useErrorMessage();
  const add = useCartMutation((m, id: number) => api.setCartItem(m, id, 1));
  if (!listingId || !inStock) return null;
  return (
    <div className="flex flex-col gap-1">
      <Button size="sm" disabled={add.isPending} onClick={() => add.mutate(listingId)}>
        <ShoppingCart className="size-4" />
        {add.isSuccess ? tp("added") : add.isPending ? tp("adding") : tp("addToCart")}
      </Button>
      {add.error ? <span className="text-caption text-danger-text">{message(add.error)}</span> : null}
    </div>
  );
}
