"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { ChevronDown, LayoutGrid, X } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useEffect, useId, useRef, useState, type RefObject } from "react";
import * as api from "@/lib/api/endpoints";
import type { MenuBrand, MenuCategory } from "@/lib/api/schemas";
import { queryKeys } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/** Header mega menu data (`catalog/menu`): top level → level-2 columns → level-3 links + top brands. */
export function useCategoryMenu() {
  const market = useMarket();
  return useQuery({
    queryKey: queryKeys.categoryMenu(market.slug),
    staleTime: 10 * 60_000,
    queryFn: async () => (await api.getCategoryMenu(market.slug)).data,
  });
}

export const flyoutId = (slug: string) => `mega-menu-${slug}`;

/** First focusable link inside an element (ArrowDown from a nav item moves focus here). */
export function focusFirstLink(container: HTMLElement | null) {
  container?.querySelector<HTMLElement>("a[href]")?.focus();
}

/**
 * Full-width hover panel of one top-level category: level-2 columns with
 * their level-3 links, the category image on the far side (end: right in
 * LTR, left in RTL) and a "Top brands" row.
 */
export function CategoryFlyout({
  category,
  panelRef,
  onNavigate,
  onEscape,
  onMouseEnter,
}: {
  category: MenuCategory;
  panelRef?: RefObject<HTMLDivElement | null>;
  onNavigate: () => void;
  onEscape: () => void;
  onMouseEnter?: () => void;
}) {
  const t = useTranslations("megaMenu");
  const href = useHref();
  const market = useMarket();
  const headingId = useId();

  return (
    <div
      ref={panelRef}
      id={flyoutId(category.slug)}
      role="region"
      aria-labelledby={headingId}
      dir={market.dir}
      data-testid="mega-menu-panel"
      onMouseEnter={onMouseEnter}
      onKeyDown={(e) => {
        if (e.key === "Escape") {
          e.stopPropagation();
          onEscape();
        }
      }}
      className="absolute inset-x-0 top-full z-40 hidden px-0 md:block md:px-8"
    >
      <div className="mx-auto flex max-h-[75vh] max-w-(--container-page) overflow-hidden rounded-b-lg border-t border-line bg-surface shadow-lg" data-testid="mega-menu-row">
        <div className="min-w-0 flex-1 overflow-y-auto p-6">
          <div className="mb-4 flex items-baseline justify-between gap-4">
            <h2 id={headingId} className="text-h4 font-semibold">
              <Link href={href(category.path)} onClick={onNavigate} className="hover:underline">
                {category.name}
              </Link>
            </h2>
            <Link href={href(category.path)} onClick={onNavigate} className="shrink-0 text-body text-link hover:underline">
              {t("shopAll", { name: category.name })} <span aria-hidden className="inline-block rtl:rotate-180">›</span>
            </Link>
          </div>

          {category.children.length > 0 ? (
            <div className="grid grid-cols-2 gap-x-6 gap-y-6 lg:grid-cols-4 xl:grid-cols-5">
              {category.children.map((column) => (
                <section key={column.slug} data-testid="mega-menu-column">
                  <h3 className="mb-2 text-body font-semibold">
                    <Link href={href(column.path)} onClick={onNavigate} className="text-ink hover:text-brand-text hover:underline">
                      {column.name}
                    </Link>
                  </h3>
                  <ul className="space-y-1.5">
                    {column.children.map((link) => (
                      <li key={link.slug}>
                        <Link href={href(link.path)} onClick={onNavigate} data-testid="mega-menu-leaf" className="text-body text-secondary hover:text-ink hover:underline">
                          {link.name}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </section>
              ))}
            </div>
          ) : null}

          {category.brands.length > 0 ? (
            <section className="mt-6 border-t border-line pt-4" data-testid="mega-menu-brands">
              <h3 className="mb-3 text-body font-semibold">{t("topBrands")}</h3>
              <ul className="flex flex-wrap gap-3">
                {category.brands.map((brand) => (
                  <li key={brand.slug}>
                    <BrandTile brand={brand} onNavigate={onNavigate} />
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>

        {category.image ? (
          <Link
            href={href(category.path)}
            onClick={onNavigate}
            data-testid="mega-menu-image"
            className="group relative hidden w-72 shrink-0 overflow-hidden bg-subtle lg:block xl:w-80"
          >
            <img src={category.image} alt={category.name} className="size-full object-cover transition duration-300 group-hover:scale-105" />
            <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-4 text-body font-semibold text-white">{t("shopAll", { name: category.name })}</span>
          </Link>
        ) : null}
      </div>
    </div>
  );
}

/** A brand tile: the brand logo, or a styled text tile when the brand has none (or it fails to load). */
export function BrandTile({ brand, onNavigate, compact = false }: { brand: MenuBrand; onNavigate: () => void; compact?: boolean }) {
  const href = useHref();
  const [broken, setBroken] = useState(false);
  const logo = brand.logo && !broken ? brand.logo : null;

  return (
    <Link
      href={href(brand.path)}
      onClick={onNavigate}
      data-testid="mega-menu-brand"
      data-brand={brand.slug}
      className={clsx("flex flex-col items-center gap-1.5 text-center hover:text-brand-text", compact ? "w-20" : "w-24")}
    >
      <span
        className={clsx(
          "flex items-center justify-center overflow-hidden rounded-lg border border-line bg-surface",
          compact ? "size-16" : "size-20",
        )}
      >
        {logo ? (
          <img src={logo} alt="" className="size-full object-contain p-2" onError={() => setBroken(true)} />
        ) : (
          <span data-testid="brand-text-tile" aria-hidden className="flex size-full items-center justify-center bg-subtle px-1.5 text-caption leading-tight font-bold tracking-tight text-ink uppercase break-words">
            {brand.name}
          </span>
        )}
      </span>
      <span className="line-clamp-1 w-full text-caption text-secondary">{brand.name}</span>
    </Link>
  );
}

/**
 * Mobile equivalent of the mega menu: a drawer of top-level categories
 * (accordion) → level-2 accordion → level-3 links, the category image and
 * its top brands. Closes on Escape, the close button, the backdrop and
 * navigation; focus returns to the opener.
 */
export function MobileCategoryDrawer({ categories }: { categories: MenuCategory[] }) {
  const t = useTranslations("megaMenu");
  const href = useHref();
  const market = useMarket();
  const [open, setOpen] = useState(false);
  const [root, setRoot] = useState<string | null>(null);
  const [column, setColumn] = useState<string | null>(null);
  const opener = useRef<HTMLButtonElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);
  const titleId = useId();

  const close = (restoreFocus = true) => {
    setOpen(false);
    if (restoreFocus) opener.current?.focus();
  };

  useEffect(() => {
    if (!open) return;
    closeButton.current?.focus();
    const esc = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setOpen(false);
        opener.current?.focus();
      }
    };
    document.addEventListener("keydown", esc);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", esc);
      document.body.style.overflow = overflow;
    };
  }, [open]);

  const navigate = () => close(false);

  return (
    <>
      <button
        ref={opener}
        type="button"
        onClick={() => setOpen(true)}
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-label={t("openDrawer")}
        className="flex shrink-0 items-center gap-1 text-body font-medium md:hidden"
      >
        <LayoutGrid className="size-4" />
        {t("browse")}
      </button>

      {open ? (
        <div className="fixed inset-0 z-50 md:hidden" dir={market.dir}>
          <div className="absolute inset-0 bg-black/40" aria-hidden onClick={() => close()} data-testid="mega-drawer-backdrop" />
          <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="absolute inset-y-0 start-0 flex w-[88vw] max-w-sm flex-col bg-surface shadow-xl">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
              <h2 id={titleId} className="text-h4 font-semibold">
                {t("browse")}
              </h2>
              <button ref={closeButton} type="button" onClick={() => close()} aria-label={t("closeDrawer")} className="rounded p-1">
                <X className="size-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto">
              {categories.length === 0 ? <p className="p-4 text-body text-secondary">{t("noCategories")}</p> : null}
              <ul>
                {categories.map((category) => {
                  const expanded = root === category.slug;
                  const panelId = `mega-drawer-${category.slug}`;
                  return (
                    <li key={category.slug} className="border-b border-line">
                      <button
                        type="button"
                        aria-expanded={expanded}
                        aria-controls={panelId}
                        onClick={() => {
                          setRoot(expanded ? null : category.slug);
                          setColumn(null);
                        }}
                        className="flex w-full items-center justify-between px-4 py-3 text-start text-body font-semibold"
                      >
                        {category.name}
                        <ChevronDown className={clsx("size-4 transition", expanded && "rotate-180")} />
                      </button>
                      {expanded ? (
                        <div id={panelId} role="region" aria-label={category.name} className="space-y-3 px-4 pb-4">
                          {category.image ? (
                            <Link href={href(category.path)} onClick={navigate} className="relative block h-28 overflow-hidden rounded-lg bg-subtle" data-testid="mega-drawer-image">
                              <img src={category.image} alt={category.name} className="size-full object-cover" />
                              <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 text-caption font-semibold text-white">
                                {t("shopAll", { name: category.name })}
                              </span>
                            </Link>
                          ) : (
                            <Link href={href(category.path)} onClick={navigate} className="block text-body text-link">
                              {t("shopAll", { name: category.name })}
                            </Link>
                          )}
                          <ul className="rounded-lg border border-line">
                            {category.children.map((level2) => {
                              const open2 = column === level2.slug;
                              const listId = `mega-drawer-${category.slug}-${level2.slug}`;
                              return (
                                <li key={level2.slug} className="border-b border-line last:border-b-0">
                                  <button
                                    type="button"
                                    aria-expanded={open2}
                                    aria-controls={listId}
                                    onClick={() => setColumn(open2 ? null : level2.slug)}
                                    className="flex w-full items-center justify-between px-3 py-2.5 text-start text-body"
                                  >
                                    {level2.name}
                                    <ChevronDown className={clsx("size-4 transition", open2 && "rotate-180")} />
                                  </button>
                                  {open2 ? (
                                    <ul id={listId} className="space-y-2 px-5 pb-3">
                                      <li>
                                        <Link href={href(level2.path)} onClick={navigate} className="text-body font-medium text-link">
                                          {t("viewAll")}
                                        </Link>
                                      </li>
                                      {level2.children.map((link) => (
                                        <li key={link.slug}>
                                          <Link href={href(link.path)} onClick={navigate} className="text-body text-secondary">
                                            {link.name}
                                          </Link>
                                        </li>
                                      ))}
                                    </ul>
                                  ) : null}
                                </li>
                              );
                            })}
                          </ul>
                          {category.brands.length > 0 ? (
                            <section>
                              <h3 className="mb-2 text-body font-semibold">{t("topBrands")}</h3>
                              <ul className="flex flex-wrap gap-2">
                                {category.brands.map((brand) => (
                                  <li key={brand.slug}>
                                    <BrandTile brand={brand} onNavigate={navigate} compact />
                                  </li>
                                ))}
                              </ul>
                            </section>
                          ) : null}
                        </div>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}
