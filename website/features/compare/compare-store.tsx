"use client";

import clsx from "clsx";
import { GitCompareArrows, X } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useState } from "react";
import { ProductImage } from "@/components/ui/misc";
import { useHref, useMarket } from "@/lib/market-context";

export const COMPARE_MAX = 4;
export type CompareEntry = { slug: string; title: string; image: string | null };

const key = (country: string) => `nawy.compare.${country}`;
const EVENT = "nawy:compare";

function read(country: string): CompareEntry[] {
  try {
    const list = JSON.parse(window.localStorage.getItem(key(country)) ?? "[]") as CompareEntry[];
    return Array.isArray(list) ? list.slice(0, COMPARE_MAX) : [];
  } catch {
    return [];
  }
}

/** Products picked for comparison on this device (2–4, per country). */
export function useCompareList() {
  const market = useMarket();
  const country = market.country.code;
  const [items, setItems] = useState<CompareEntry[]>([]);
  useEffect(() => {
    const sync = () => setItems(read(country));
    sync();
    window.addEventListener(EVENT, sync);
    window.addEventListener("storage", sync);
    return () => {
      window.removeEventListener(EVENT, sync);
      window.removeEventListener("storage", sync);
    };
  }, [country]);
  const save = useCallback(
    (next: CompareEntry[]) => {
      try {
        window.localStorage.setItem(key(country), JSON.stringify(next.slice(0, COMPARE_MAX)));
      } catch {
        // Storage disabled: keep the in-memory list.
      }
      setItems(next.slice(0, COMPARE_MAX));
      window.dispatchEvent(new Event(EVENT));
    },
    [country],
  );
  return {
    items,
    has: (slug: string) => items.some((i) => i.slug === slug),
    add: (entry: CompareEntry) => save([...items.filter((i) => i.slug !== entry.slug), entry]),
    remove: (slug: string) => save(items.filter((i) => i.slug !== slug)),
    clear: () => save([]),
  };
}

export function compareHref(href: (path: string) => string, slugs: string[]): string {
  return `${href("/compare")}?slugs=${slugs.map(encodeURIComponent).join(",")}`;
}

/** PDP "Add to compare" toggle (max 4). */
export function CompareToggle({ product }: { product: CompareEntry }) {
  const t = useTranslations("discovery.compare");
  const list = useCompareList();
  const active = list.has(product.slug);
  const full = !active && list.items.length >= COMPARE_MAX;
  return (
    <button
      type="button"
      aria-pressed={active}
      disabled={full}
      title={full ? t("full", { max: COMPARE_MAX }) : undefined}
      onClick={() => (active ? list.remove(product.slug) : list.add(product))}
      className={clsx("flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-body-sm font-medium disabled:opacity-50", active ? "border-brand bg-brand-subtle" : "border-line bg-surface hover:bg-subtle")}
    >
      <GitCompareArrows className="size-4" />
      {active ? t("added") : t("add")}
    </button>
  );
}

/** Floating tray with the picked products and a "Compare" link (shown once one is picked). */
export function CompareTray() {
  const t = useTranslations("discovery.compare");
  const href = useHref();
  const list = useCompareList();
  if (list.items.length === 0) return null;
  return (
    <aside className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface shadow-lg" aria-label={t("tray")}>
      <div className="mx-auto flex max-w-(--container-page) items-center gap-3 overflow-x-auto px-4 py-2 md:px-8">
        <p className="shrink-0 text-body-sm font-semibold">{t("trayCount", { count: list.items.length, max: COMPARE_MAX })}</p>
        <ul className="flex flex-1 gap-2">
          {list.items.map((item) => (
            <li key={item.slug} className="relative flex shrink-0 items-center gap-2 rounded-md border border-line py-1 pe-7 ps-1">
              <span className="block size-9 overflow-hidden rounded-sm">
                <ProductImage src={item.image} alt={item.title} />
              </span>
              <span className="line-clamp-1 max-w-32 text-caption">{item.title}</span>
              <button type="button" onClick={() => list.remove(item.slug)} className="absolute end-1 top-1/2 -translate-y-1/2 text-muted hover:text-ink" aria-label={t("remove", { title: item.title })}>
                <X className="size-4" />
              </button>
            </li>
          ))}
        </ul>
        <button type="button" onClick={list.clear} className="shrink-0 text-body-sm text-secondary hover:text-ink">
          {t("clear")}
        </button>
        {list.items.length >= 2 ? (
          <Link href={compareHref(href, list.items.map((i) => i.slug))} className="shrink-0 rounded-md bg-ink px-4 py-2 text-body-sm font-semibold text-white">
            {t("compareNow")}
          </Link>
        ) : (
          <span className="shrink-0 text-caption text-secondary">{t("pickMore")}</span>
        )}
      </div>
    </aside>
  );
}
