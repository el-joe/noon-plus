"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, ProductImage } from "@/components/ui/misc";
import { getFrequentlyBoughtTogether } from "@/lib/api/discovery";
import * as api from "@/lib/api/endpoints";
import type { Money } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { useCartMutation, useErrorMessage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

type Line = { slug: string; title: string; image: string | null; price: Money | null; listingId: number | null; self: boolean };

/** Sum money strings in minor units (no floats). */
function sum(prices: (Money | null)[], decimals: number): Money | null {
  const known = prices.filter((p): p is Money => !!p);
  if (known.length === 0) return null;
  let total = BigInt(0);
  for (const p of known) {
    const [whole, frac = ""] = p.amount.split(".");
    total += BigInt(whole + frac.padEnd(decimals, "0").slice(0, decimals));
  }
  const text = total.toString().padStart(decimals + 1, "0");
  return { amount: decimals > 0 ? `${text.slice(0, -decimals)}.${text.slice(-decimals)}` : text, currency: known[0].currency };
}

/**
 * Frequently bought together (Figma 24:2, QC-043 / QC-057): this product plus
 * up to 4 items co-ordered in the last 90 days. Untick to leave one out;
 * "Add all" puts each ticked listing in the cart (PUT cart/items).
 */
export function FrequentlyBoughtTogether({ product }: { product: { slug: string; title: string; image: string | null; price: Money | null } }) {
  const t = useTranslations("discovery.fbt");
  const market = useMarket();
  const href = useHref();
  const message = useErrorMessage();
  const { data } = useQuery({
    queryKey: ["fbt", market.slug, product.slug],
    staleTime: 5 * 60_000,
    queryFn: async () => (await getFrequentlyBoughtTogether(market.slug, product.slug)).data,
  });
  const [off, setOff] = useState<Set<string>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const add = useCartMutation(async (m, ids: number[]) => {
    let last: Awaited<ReturnType<typeof api.setCartItem>> | null = null;
    for (const id of ids) last = await api.setCartItem(m, id, 1);
    return last!;
  });

  if (!data || data.items.length === 0) return null;

  const lines: Line[] = [
    { slug: product.slug, title: product.title, image: product.image, price: product.price, listingId: data.product.listing_id, self: true },
    ...data.items.map((i) => ({ slug: i.slug, title: i.title, image: i.image?.url ?? null, price: i.price, listingId: i.listing_id, self: false })),
  ];
  const chosen = lines.filter((l) => !off.has(l.slug) && l.listingId);
  const total = off.size === 0 && data.bundle_total ? data.bundle_total : sum(chosen.map((l) => l.price), market.country.decimals);

  return (
    <section className="rounded-lg border border-line bg-surface p-5" aria-labelledby="fbt-title">
      <h2 id="fbt-title" className="mb-4 text-h4 font-semibold">
        {t("title")}
      </h2>
      <div className="flex flex-col gap-5 lg:flex-row lg:items-center">
        <ol className="flex flex-1 flex-wrap items-start gap-2">
          {lines.map((line, i) => (
            <li key={line.slug} className="flex items-start gap-2">
              {i > 0 ? <Plus className="mt-12 size-4 shrink-0 text-muted" aria-hidden /> : null}
              <label className="flex w-32 flex-col gap-1.5 text-body-sm">
                <span className="relative block aspect-square overflow-hidden rounded-md border border-line">
                  <ProductImage src={line.image} alt={line.title} />
                  <input
                    type="checkbox"
                    checked={!off.has(line.slug)}
                    disabled={!line.listingId}
                    onChange={(e) => {
                      const next = new Set(off);
                      if (e.target.checked) next.delete(line.slug);
                      else next.add(line.slug);
                      setOff(next);
                    }}
                    className="absolute start-2 top-2 size-4 accent-[var(--color-brand)]"
                    aria-label={t("include", { title: line.title })}
                  />
                </span>
                {line.self ? (
                  <span className="line-clamp-2">
                    {line.title} <span className="text-secondary">{t("thisItem")}</span>
                  </span>
                ) : (
                  <Link href={href(`/products/${encodeURIComponent(line.slug)}`)} className="line-clamp-2 hover:text-link">
                    {line.title}
                  </Link>
                )}
                <span className="font-semibold">{formatMoney(line.price)}</span>
              </label>
            </li>
          ))}
        </ol>
        <div className="flex flex-col items-start gap-2 lg:items-end lg:text-end">
          <p className="text-body-sm text-secondary">{t("totalFor", { count: chosen.length })}</p>
          <p className="text-h3 font-bold">{formatMoney(total)}</p>
          <Button
            disabled={chosen.length === 0 || add.isPending}
            onClick={() => {
              setError(null);
              add.mutate(
                chosen.map((l) => l.listingId!),
                { onError: (e) => setError(message(e)) },
              );
            }}
          >
            {add.isPending ? t("adding") : add.isSuccess ? t("added") : t("addAll", { count: chosen.length })}
          </Button>
          {add.isSuccess ? (
            <Link href={href("/cart")} className="text-body-sm font-medium text-link hover:underline">
              {t("viewCart")}
            </Link>
          ) : null}
          {error ? <Alert tone="danger">{error}</Alert> : null}
        </div>
      </div>
    </section>
  );
}
