"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { History, Search, Tag, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { ProductImage } from "@/components/ui/misc";
import { getSuggestions } from "@/lib/api/discovery";
import { formatMoney } from "@/lib/format";
import { useLocalStorage } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { SearchChips, usePopularSearches, useTrendingSearches } from "./search-chips";

const RECENT_SEARCHES = 8;
const searchesKey = (country: string) => `nawy.searches.${country}`;

type Option = { id: string; kind: "query" | "recent" | "category" | "brand" | "product"; label: string; href: string; image?: string | null; price?: string | null };

function useDebounced<T>(value: T, ms: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), ms);
    return () => window.clearTimeout(timer);
  }, [value, ms]);
  return debounced;
}

/**
 * Header search with the suggest dropdown (QC-043): an ARIA combobox.
 * Typing 2+ characters asks `search/suggest` (debounced) for queries,
 * categories, brands and products; an empty box shows this device's recent
 * searches and the popular / trending chips. ↑/↓ move, Enter opens, Esc closes.
 */
export function SearchBox({ className }: { className?: string }) {
  const t = useTranslations("header");
  const ts = useTranslations("discovery.search");
  const router = useRouter();
  const href = useHref();
  const market = useMarket();
  const listId = useId();
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [recent, setRecent] = useLocalStorage<string[]>(searchesKey(market.country.code), []);
  const root = useRef<HTMLFormElement>(null);
  const term = q.trim();
  const debounced = useDebounced(term, 200);

  // Mirror the query when the page is opened on /search?q=…
  useEffect(() => {
    if (window.location.pathname.endsWith("/search")) {
      setQ(new URLSearchParams(window.location.search).get("q") ?? "");
    }
  }, []);

  // Close on a click outside.
  useEffect(() => {
    if (!open) return;
    const onDown = (event: MouseEvent) => {
      if (root.current && !root.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  const suggest = useQuery({
    queryKey: ["search-suggest", market.slug, debounced],
    enabled: open && debounced.length >= 2,
    staleTime: 60_000,
    queryFn: async ({ signal }) => (await getSuggestions(market.slug, debounced.slice(0, 100), signal)).data,
  });

  const searchHref = (value: string) => `${href("/search")}?q=${encodeURIComponent(value)}`;
  const typing = term.length >= 2;
  const data = typing && suggest.data && debounced === term ? suggest.data : null;
  const options: Option[] = typing
    ? data
      ? [
          ...data.queries.map((v, i) => ({ id: `q${i}`, kind: "query" as const, label: v, href: searchHref(v) })),
          ...data.categories.map((c) => ({ id: `c-${c.slug}`, kind: "category" as const, label: c.name, href: href(`/${encodeURIComponent(c.slug)}`) })),
          ...data.brands.map((b) => ({ id: `b-${b.slug}`, kind: "brand" as const, label: b.name, href: href(`/brands/${encodeURIComponent(b.slug)}`), image: b.logo ?? null })),
          ...data.products.map((p) => ({ id: `p-${p.slug}`, kind: "product" as const, label: p.title, href: href(`/products/${encodeURIComponent(p.slug)}`), image: p.image?.url ?? null, price: p.price ? formatMoney(p.price) : null })),
        ]
      : []
    : recent.map((v, i) => ({ id: `r${i}`, kind: "recent" as const, label: v, href: searchHref(v) }));

  const remember = (value: string) => setRecent([value, ...recent.filter((r) => r.toLowerCase() !== value.toLowerCase())].slice(0, RECENT_SEARCHES));

  const go = (option: Option) => {
    if (option.kind === "query" || option.kind === "recent") {
      remember(option.label);
      setQ(option.label);
    }
    setOpen(false);
    setActive(-1);
    router.push(option.href);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (open && active >= 0 && options[active]) {
      go(options[active]);
      return;
    }
    if (term) {
      remember(term);
      setOpen(false);
      router.push(searchHref(term));
    }
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setOpen(true);
      setActive((i) => (options.length === 0 ? -1 : (i + 1) % options.length));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setActive((i) => (options.length === 0 ? -1 : i <= 0 ? options.length - 1 : i - 1));
    } else if (event.key === "Escape") {
      if (open) {
        event.preventDefault();
        setOpen(false);
        setActive(-1);
      }
    }
  };

  const groupLabel: Record<Option["kind"], string> = {
    query: ts("suggestions"),
    recent: ts("recent"),
    category: ts("categories"),
    brand: ts("brands"),
    product: ts("products"),
  };

  const activeId = active >= 0 && options[active] ? `${listId}-${options[active].id}` : undefined;
  const popular = usePopularSearches(open);
  const trending = useTrendingSearches(open);
  const hasChips = (popular.data?.length ?? 0) > 0 || (trending.data?.length ?? 0) > 0;
  const showPanel = open && (typing || recent.length > 0 || hasChips);

  return (
    <form ref={root} role="search" onSubmit={submit} className={clsx("relative flex h-11 items-center gap-2 rounded-md bg-surface ps-4 pe-1", className)}>
      <input
        type="search"
        name="q"
        value={q}
        onChange={(e) => {
          setQ(e.target.value);
          setOpen(true);
          setActive(-1);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder={t("searchPlaceholder")}
        aria-label={t("search")}
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={showPanel}
        aria-controls={listId}
        aria-activedescendant={activeId}
        autoComplete="off"
        maxLength={100}
        className="h-full min-w-0 flex-1 bg-transparent text-body-lg text-ink placeholder:text-muted focus:outline-none"
      />
      <button type="submit" className="flex h-9 w-10 items-center justify-center rounded-sm bg-ink text-white" aria-label={t("search")}>
        <Search className="size-[18px]" />
      </button>
      {showPanel ? (
        <div className="absolute inset-x-0 top-full z-50 mt-1 max-h-[70vh] overflow-y-auto rounded-md border border-line bg-surface p-3 text-ink shadow-lg">
          <ul id={listId} role="listbox" aria-label={ts("suggestions")} className="flex flex-col">
            {options.map((option, i) => {
              const first = i === 0 || options[i - 1].kind !== option.kind;
              return (
                <li key={option.id} role="presentation">
                  {first ? (
                    <p className="flex items-center justify-between px-2 pb-1 pt-2 text-caption font-medium uppercase text-muted" aria-hidden>
                      {groupLabel[option.kind]}
                      {option.kind === "recent" ? (
                        <button
                          type="button"
                          onMouseDown={(e) => e.preventDefault()}
                          onClick={() => setRecent([])}
                          className="normal-case text-link hover:underline"
                        >
                          {ts("clearRecent")}
                        </button>
                      ) : null}
                    </p>
                  ) : null}
                  <div
                    id={`${listId}-${option.id}`}
                    role="option"
                    aria-selected={i === active}
                    onMouseDown={(e) => e.preventDefault()}
                    onMouseEnter={() => setActive(i)}
                    onClick={() => go(option)}
                    className={clsx("flex cursor-pointer items-center gap-3 rounded-sm px-2 py-1.5 text-body", i === active ? "bg-subtle" : "")}
                  >
                    {option.kind === "product" || option.kind === "brand" ? (
                      <span className="block size-9 shrink-0 overflow-hidden rounded-sm border border-line">
                        <ProductImage src={option.image} alt="" />
                      </span>
                    ) : option.kind === "recent" ? (
                      <History className="size-4 shrink-0 text-muted" />
                    ) : option.kind === "category" ? (
                      <Tag className="size-4 shrink-0 text-muted" />
                    ) : (
                      <Search className="size-4 shrink-0 text-muted" />
                    )}
                    <span className="line-clamp-1 flex-1">{option.label}</span>
                    {option.price ? <span className="shrink-0 text-body-sm font-semibold">{option.price}</span> : null}
                    {option.kind === "recent" ? (
                      <button
                        type="button"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={(e) => {
                          e.stopPropagation();
                          setRecent(recent.filter((r) => r !== option.label));
                        }}
                        className="text-muted hover:text-ink"
                        aria-label={ts("removeRecent", { query: option.label })}
                      >
                        <X className="size-4" />
                      </button>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ul>
          {typing && data && options.length === 0 ? <p className="px-2 py-2 text-body-sm text-secondary">{ts("noSuggestions", { query: term })}</p> : null}
          {typing && !data && suggest.isFetching ? <p className="px-2 py-2 text-body-sm text-secondary">{ts("loading")}</p> : null}
          {typing ? (
            <button
              type="button"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => {
                remember(term);
                setOpen(false);
                router.push(searchHref(term));
              }}
              className="mt-1 flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-body-sm font-medium text-link hover:bg-subtle"
            >
              <Search className="size-4" />
              {ts("searchFor", { query: term })}
            </button>
          ) : (
            <SearchChips onPick={() => setOpen(false)} className="mt-2 flex flex-col gap-3 px-2" />
          )}
        </div>
      ) : null}
    </form>
  );
}
