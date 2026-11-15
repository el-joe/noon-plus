"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { ChevronDown, ChevronRight } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import * as api from "@/lib/api/endpoints";
import type { Category } from "@/lib/api/schemas";
import { queryKeys } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

export function categoryPath(parent: Category | null, child?: Category | null): string {
  return parent && child ? `/${parent.slug}/${child.slug}` : `/${(child ?? parent)?.slug ?? ""}`;
}

export function useCategories() {
  const market = useMarket();
  return useQuery({
    queryKey: queryKeys.categories(market.slug),
    staleTime: 10 * 60_000,
    queryFn: async () => (await api.getCategories(market.slug)).data,
  });
}

/** Category nav bar + "All categories" mega menu (Figma 17:94 / 107:16631). */
export function CategoryNav() {
  const t = useTranslations();
  const href = useHref();
  const { data: categories = [] } = useCategories();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => panel.current && !panel.current.contains(e.target as Node) && setOpen(false);
    const esc = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const current = categories[active] ?? null;

  return (
    <div ref={panel} className="flex min-w-0 flex-1 items-center gap-6">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className={clsx("flex shrink-0 items-center gap-1 text-body font-medium", open && "text-brand-text")}
      >
        {t("header.allCategories")}
        <ChevronDown className={clsx("size-4 transition", open && "rotate-180")} />
      </button>
      <span className="h-5 w-px shrink-0 bg-line" aria-hidden />
      <nav className="scrollbar-none flex min-w-0 items-center gap-5 overflow-x-auto whitespace-nowrap">
        {categories.map((category) => (
          <Link key={category.slug} href={href(categoryPath(category))} className="text-body text-ink hover:text-brand-text">
            {category.name}
          </Link>
        ))}
      </nav>

      {open ? (
        <div className="absolute inset-x-0 top-full z-40 px-0 md:px-8">
          <div className="mx-auto flex max-h-[70vh] max-w-(--container-page) overflow-hidden rounded-b-lg bg-surface shadow-lg">
            {categories.length === 0 ? (
              <p className="p-6 text-body text-secondary">{t("megaMenu.noCategories")}</p>
            ) : (
              <>
                <ul className="w-56 shrink-0 overflow-y-auto bg-subtle py-2 md:w-64">
                  {categories.map((category, index) => (
                    <li key={category.slug}>
                      <button
                        type="button"
                        onMouseEnter={() => setActive(index)}
                        onFocus={() => setActive(index)}
                        onClick={() => setActive(index)}
                        className={clsx(
                          "mx-2 flex w-[calc(100%-1rem)] items-center justify-between rounded-md px-3 py-2.5 text-start text-body",
                          index === active ? "bg-surface font-semibold shadow-xs" : "text-secondary",
                        )}
                      >
                        {category.name}
                        <ChevronRight className="size-4 rtl:rotate-180" />
                      </button>
                    </li>
                  ))}
                </ul>
                {current ? (
                  <div className="flex-1 overflow-y-auto p-6">
                    <Link href={href(categoryPath(current))} onClick={() => setOpen(false)} className="mb-4 inline-block text-h4 font-semibold hover:underline">
                      {current.name}
                    </Link>
                    <ul className="grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
                      {(current.children ?? []).map((child) => (
                        <li key={child.slug}>
                          <Link href={href(categoryPath(current, child))} onClick={() => setOpen(false)} className="text-body text-secondary hover:text-ink">
                            {child.name}
                          </Link>
                        </li>
                      ))}
                      <li>
                        <Link href={href(categoryPath(current))} onClick={() => setOpen(false)} className="text-body text-link">
                          {t("megaMenu.viewAll")} ›
                        </Link>
                      </li>
                    </ul>
                  </div>
                ) : null}
              </>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}
