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
import { CategoryFlyout, flyoutId, focusFirstLink, MobileCategoryDrawer, useCategoryMenu } from "./category-flyout";

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

/** Hover intent: open after a short pause, close after leaving nav and panel. */
const OPEN_DELAY = 150;
const CLOSE_DELAY = 200;

/**
 * Category nav bar + "All categories" mega menu (Figma 17:94 / 107:16631),
 * a per-category hover / keyboard flyout (level-2 columns, level-3 links,
 * category image, top brands) and, on mobile, the category drawer.
 */
export function CategoryNav() {
  const t = useTranslations();
  const href = useHref();
  const { data: categories = [] } = useCategories();
  const { data: menu = [] } = useCategoryMenu();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [flyout, setFlyout] = useState<string | null>(null);
  const panel = useRef<HTMLDivElement>(null);
  const flyoutPanel = useRef<HTMLDivElement>(null);
  const triggers = useRef(new Map<string, HTMLAnchorElement>());
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const focusPanelOnOpen = useRef(false);

  const menuBySlug = new Map(menu.map((category) => [category.slug, category]));
  const flyoutCategory = flyout ? (menuBySlug.get(flyout) ?? null) : null;

  const clearTimer = () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
  };
  const openFlyout = (slug: string) => {
    clearTimer();
    setOpen(false);
    setFlyout(slug);
  };
  const closeFlyout = () => {
    clearTimer();
    setFlyout(null);
  };
  const hoverOpen = (slug: string) => {
    clearTimer();
    if (!menuBySlug.has(slug)) return;
    // Switching between categories while one is open is immediate; the first open waits for intent.
    timer.current = setTimeout(() => openFlyout(slug), flyout ? 0 : OPEN_DELAY);
  };
  const hoverClose = () => {
    clearTimer();
    timer.current = setTimeout(() => setFlyout(null), CLOSE_DELAY);
  };
  const escapeFlyout = () => {
    const slug = flyout;
    closeFlyout();
    if (slug) triggers.current.get(slug)?.focus();
  };

  useEffect(() => clearTimer, []);

  useEffect(() => {
    if (flyout && focusPanelOnOpen.current) {
      focusPanelOnOpen.current = false;
      focusFirstLink(flyoutPanel.current);
    }
  }, [flyout]);

  useEffect(() => {
    if (!flyout) return;
    const esc = (e: KeyboardEvent) => e.key === "Escape" && escapeFlyout();
    const outside = (e: MouseEvent) => panel.current && !panel.current.contains(e.target as Node) && closeFlyout();
    document.addEventListener("keydown", esc);
    document.addEventListener("mousedown", outside);
    return () => {
      document.removeEventListener("keydown", esc);
      document.removeEventListener("mousedown", outside);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [flyout]);

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
    <div
      ref={panel}
      className="flex min-w-0 flex-1 items-center gap-6 self-stretch"
      onMouseLeave={hoverClose}
      onBlur={(e) => {
        // Keyboard: the flyout closes once focus leaves the nav bar and the panel.
        if (flyout && !e.currentTarget.contains(e.relatedTarget as Node | null)) closeFlyout();
      }}
    >
      <MobileCategoryDrawer categories={menu} />
      <button
        type="button"
        onClick={() => {
          closeFlyout();
          setOpen((v) => !v);
        }}
        aria-expanded={open}
        className={clsx("hidden shrink-0 items-center gap-1 text-body font-medium md:flex", open && "text-brand-text")}
      >
        {t("header.allCategories")}
        <ChevronDown className={clsx("size-4 transition", open && "rotate-180")} />
      </button>
      <span className="hidden h-5 w-px shrink-0 bg-line md:block" aria-hidden />
      <nav className="scrollbar-none flex min-w-0 items-center gap-5 self-stretch overflow-x-auto whitespace-nowrap" aria-label={t("megaMenu.browse")}>
        {categories.map((category) => {
          const hasMenu = menuBySlug.has(category.slug);
          const expanded = flyout === category.slug;
          return (
            // The nav bar sits inside the menu's outside-click boundary: close the open menu on navigation.
            <Link
              key={category.slug}
              ref={(node) => {
                if (node) triggers.current.set(category.slug, node);
                else triggers.current.delete(category.slug);
              }}
              href={href(categoryPath(category))}
              onClick={() => {
                setOpen(false);
                closeFlyout();
              }}
              onMouseEnter={() => hoverOpen(category.slug)}
              onKeyDown={(e) => {
                if (!hasMenu) return;
                if (e.key === "ArrowDown" || ((e.key === "Enter" || e.key === " ") && !expanded)) {
                  e.preventDefault();
                  focusPanelOnOpen.current = e.key === "ArrowDown";
                  if (expanded && e.key === "ArrowDown") focusFirstLink(flyoutPanel.current);
                  else openFlyout(category.slug);
                } else if (e.key === "Escape" && expanded) {
                  e.preventDefault();
                  closeFlyout();
                }
              }}
              aria-expanded={hasMenu ? expanded : undefined}
              aria-controls={hasMenu && expanded ? flyoutId(category.slug) : undefined}
              aria-haspopup={hasMenu ? "true" : undefined}
              data-menu-trigger={category.slug}
              className={clsx("flex h-full items-center border-b-2 text-body text-ink hover:text-brand-text", expanded ? "border-brand-text text-brand-text" : "border-transparent")}
            >
              {category.name}
            </Link>
          );
        })}
      </nav>

      {flyoutCategory ? (
        <CategoryFlyout category={flyoutCategory} panelRef={flyoutPanel} onNavigate={closeFlyout} onEscape={escapeFlyout} onMouseEnter={clearTimer} />
      ) : null}

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
