import clsx from "clsx";
import { SlidersHorizontal, Star, X } from "lucide-react";
import { cookies } from "next/headers";
import Link from "next/link";
import { ProductGrid } from "@/components/product/product-grid";
import { Alert, EmptyState } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { getFacets, getProducts, type ProductFilters } from "@/lib/api/endpoints";
import { cardFromApi } from "@/lib/api/mappers";
import type { Category, CategoryNode, Facets } from "@/lib/api/schemas";
import { formatAmount, humanize } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { VISITOR_COOKIE } from "@/lib/session";
import { SortSelect } from "./sort-select";

export type ListingQuery = Record<string, string | undefined>;

const SORTS = new Set(["recommended", "newest", "price_asc", "price_desc", "rating"]);
const SLUGS = /^[a-z0-9-]+(,[a-z0-9-]+)*$/i;

/** Search params → listing query: repeated keys (checkbox groups) are joined with commas. */
export function toListingQuery(raw: Record<string, string | string[] | undefined>): ListingQuery {
  return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, Array.isArray(v) ? v.filter(Boolean).join(",") : v]));
}

/** Read listing filters from search params (validated against ProductIndexRequest). */
export function listingFilters(query: ListingQuery): ProductFilters {
  const price = (v?: string) => (v && /^\d+(\.\d+)?$/.test(v) ? v : undefined);
  const slugs = (v?: string) => (v && v.length <= 1000 && SLUGS.test(v) ? v : undefined);
  const page = Number(query.page);
  const rating = Number(query.rating);
  const attr: Record<string, string> = {};
  for (const [key, value] of Object.entries(query)) {
    const m = /^attr\[([a-z0-9_-]{1,40})\]$/i.exec(key);
    if (m && value) attr[m[1]] = value.slice(0, 500);
  }
  return {
    q: query.q?.slice(0, 100) || undefined,
    brand: slugs(query.brand),
    seller: slugs(query.seller),
    min_price: price(query.min_price),
    max_price: price(query.max_price),
    rating: Number.isInteger(rating) && rating >= 1 && rating <= 5 ? String(rating) : undefined,
    sort: SORTS.has(query.sort ?? "") ? (query.sort as ProductFilters["sort"]) : undefined,
    page: Number.isInteger(page) && page > 1 ? page : undefined,
    per_page: 30,
    attr: Object.keys(attr).length ? attr : undefined,
  };
}

export type TreeNode = { label: string; href: string; active: boolean; depth: number; count?: number };

/**
 * Product listing zone (block 32 `product_listing`; Figma Category v2 40:4438 /
 * Search results 67:8168): facet sidebar with counts (G-W4: brands, sellers,
 * price, rating, variant attributes), results header + sort, grid with
 * sponsored interleave ("Ad" labels from SponsoredRanker) and pagination.
 */
export async function ProductListing({
  market,
  query,
  basePath,
  category,
  tree,
}: {
  market: Market;
  query: ListingQuery;
  basePath: string;
  category?: string | null;
  tree?: TreeNode[];
}) {
  const t = getT(market.locale);
  const filters = { ...listingFilters(query), category: category ?? undefined };
  const visitor = (await cookies()).get(VISITOR_COOKIE)?.value;

  let result: Awaited<ReturnType<typeof getProducts>> | null = null;
  let error: string | null = null;
  const [productsResult, facetsResult] = await Promise.allSettled([
    getProducts(market.slug, filters, visitor ? { "X-Visitor-Token": visitor } : undefined),
    getFacets(market.slug, filters),
  ]);
  if (productsResult.status === "fulfilled") result = productsResult.value;
  else error = productsResult.reason instanceof ApiError && productsResult.reason.status === 422 ? productsResult.reason.message : t("common.unreachable");
  const facets: Facets | null = facetsResult.status === "fulfilled" ? facetsResult.value.data : null;

  const products = result?.data ?? [];
  const total = Number(result?.meta.total ?? products.length);
  const page = Number(result?.meta.page ?? 1);
  const last = Number(result?.meta.last_page ?? 1);

  // Every active filter, as kept in the URL.
  const keep: Record<string, string> = {};
  for (const key of ["q", "sort", "brand", "seller", "min_price", "max_price", "rating"] as const) {
    const v = query[key];
    if (v) keep[key] = v;
  }
  for (const [key, value] of Object.entries(filters.attr ?? {})) keep[`attr[${key}]`] = value;
  const hrefWith = (params: Record<string, string>) => {
    const qs = new URLSearchParams(params).toString();
    return qs ? `${basePath}?${qs}` : basePath;
  };
  const pageHref = (p: number) => hrefWith({ ...keep, ...(p > 1 ? { page: String(p) } : {}) });
  const without = (key: string, value?: string) => {
    const next = { ...keep };
    if (value && next[key]) {
      const rest = next[key].split(",").filter((v) => v !== value);
      if (rest.length) next[key] = rest.join(",");
      else delete next[key];
    } else delete next[key];
    return hrefWith(next);
  };
  const selected = (key: string) => new Set((keep[key] ?? "").split(",").filter(Boolean));

  // Active filter chips.
  const chips: { label: string; href: string }[] = [];
  for (const slug of selected("brand")) chips.push({ label: facets?.brands.find((b) => b.slug === slug)?.name ?? slug, href: without("brand", slug) });
  for (const slug of selected("seller")) chips.push({ label: facets?.sellers.find((s) => s.slug === slug)?.name ?? slug, href: without("seller", slug) });
  if (keep.rating) chips.push({ label: t("listing.ratingUp", { stars: keep.rating }), href: without("rating") });
  if (keep.min_price || keep.max_price) {
    chips.push({ label: `${market.country.currency} ${keep.min_price ?? "0"} – ${keep.max_price ?? "∞"}`, href: hrefWith(Object.fromEntries(Object.entries(keep).filter(([k]) => k !== "min_price" && k !== "max_price"))) });
  }
  for (const [key, value] of Object.entries(filters.attr ?? {})) {
    for (const v of value.split(",")) chips.push({ label: `${humanize(key)}: ${v}`, href: without(`attr[${key}]`, v) });
  }

  const checkboxGroup = (name: string, legend: string, rows: { value: string; label: string; count: number }[]) => {
    if (rows.length === 0) return null;
    const active = selected(name);
    return (
      <fieldset>
        <legend className="mb-2 text-label font-semibold">{legend}</legend>
        <ul className="flex max-h-56 flex-col gap-1.5 overflow-y-auto pe-1">
          {rows.map((row) => (
            <li key={row.value}>
              <label className="flex items-center gap-2 text-body-sm">
                <input type="checkbox" name={name} value={row.value} defaultChecked={active.has(row.value)} className="size-4 accent-ink" />
                <span className="flex-1 truncate">{row.label}</span>
                <span className="text-caption text-muted tabular-nums">{row.count}</span>
              </label>
            </li>
          ))}
        </ul>
      </fieldset>
    );
  };

  const priceMin = facets?.price.min?.amount;
  const priceMax = facets?.price.max?.amount;

  const sidebar = (
    <form method="get" action={basePath} className="flex flex-col gap-5">
      {keep.q ? <input type="hidden" name="q" value={keep.q} /> : null}
      {keep.sort ? <input type="hidden" name="sort" value={keep.sort} /> : null}
      {tree && tree.length > 0 ? (
        <fieldset>
          <legend className="mb-2 text-label font-semibold">{t("listing.category")}</legend>
          <ul className="flex flex-col gap-1.5">
            {tree.map((node) => (
              <li key={node.href} style={{ paddingInlineStart: node.depth * 12 }}>
                <Link href={node.href} className={clsx("flex items-center justify-between gap-2 text-body-sm", node.active ? "font-semibold text-ink" : "text-secondary hover:text-ink")}>
                  <span className="truncate">{node.label}</span>
                  {node.count != null ? <span className="text-caption font-normal text-muted tabular-nums">{node.count}</span> : null}
                </Link>
              </li>
            ))}
          </ul>
        </fieldset>
      ) : null}
      {checkboxGroup("brand", t("listing.brand"), (facets?.brands ?? []).map((b) => ({ value: b.slug, label: b.name, count: b.count })))}
      <fieldset>
        <legend className="mb-2 text-label font-semibold">{t("listing.price", { currency: market.country.currency })}</legend>
        <div className="flex items-center gap-2">
          <input name="min_price" inputMode="decimal" defaultValue={keep.min_price ?? ""} placeholder={priceMin ? formatAmount(priceMin) : t("listing.min")} aria-label={t("listing.min")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
          <span className="text-muted">–</span>
          <input name="max_price" inputMode="decimal" defaultValue={keep.max_price ?? ""} placeholder={priceMax ? formatAmount(priceMax) : t("listing.max")} aria-label={t("listing.max")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
        </div>
      </fieldset>
      {facets && facets.ratings.some((r) => r.count > 0) ? (
        <fieldset>
          <legend className="mb-2 text-label font-semibold">{t("listing.rating")}</legend>
          <ul className="flex flex-col gap-1.5">
            {facets.ratings.map((r) => (
              <li key={r.min}>
                <label className="flex items-center gap-2 text-body-sm">
                  <input type="radio" name="rating" value={r.min} defaultChecked={keep.rating === String(r.min)} className="size-4 accent-ink" />
                  <span className="flex flex-1 items-center gap-1">
                    {r.min}
                    <Star className="size-3.5 text-brand" fill="currentColor" strokeWidth={0} />
                    {t("listing.ratingUp", { stars: "" }).trim()}
                  </span>
                  <span className="text-caption text-muted tabular-nums">{r.count}</span>
                </label>
              </li>
            ))}
          </ul>
        </fieldset>
      ) : null}
      {checkboxGroup("seller", t("listing.seller"), (facets?.sellers ?? []).filter((s) => s.slug).map((s) => ({ value: s.slug!, label: s.name ?? s.slug!, count: s.count })))}
      {(facets?.attributes ?? []).map((attribute) => (
        <div key={attribute.key}>{checkboxGroup(`attr[${attribute.key}]`, humanize(attribute.key), attribute.values.map((v) => ({ value: v.value, label: v.value, count: v.count })))}</div>
      ))}
      <div className="flex items-center gap-3">
        <button type="submit" className="h-9 rounded-md bg-ink px-4 text-body-sm font-semibold text-white">
          {t("listing.apply")}
        </button>
        {chips.length > 0 ? (
          <Link href={hrefWith(keep.q ? { q: keep.q } : {})} className="text-body-sm text-link">
            {t("listing.clear")}
          </Link>
        ) : null}
      </div>
    </form>
  );

  return (
    <div className="flex flex-col gap-4 lg:flex-row">
      <aside className="lg:w-60 lg:shrink-0">
        <details className="rounded-lg bg-surface p-4 lg:hidden">
          <summary className="flex cursor-pointer items-center gap-2 text-body font-semibold">
            <SlidersHorizontal className="size-4" />
            {t("listing.filters")}
            {chips.length > 0 ? <span className="rounded-full bg-ink px-1.5 text-caption text-white">{chips.length}</span> : null}
          </summary>
          <div className="mt-4">{sidebar}</div>
        </details>
        <div className="hidden rounded-lg bg-surface p-4 lg:block">{sidebar}</div>
      </aside>
      <div className="min-w-0 flex-1">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <p className="text-body">
            <span className="font-semibold">{t("listing.results", { count: total })}</span>
            {keep.q ? <span className="text-secondary"> {t("listing.resultsFor", { q: keep.q })}</span> : null}
          </p>
          <SortSelect value={keep.sort ?? "recommended"} basePath={basePath} params={keep} />
        </div>
        {chips.length > 0 ? (
          <div className="mb-3 flex flex-wrap items-center gap-2" aria-label={t("listing.activeFilters")}>
            {chips.map((chip) => (
              <Link key={chip.href + chip.label} href={chip.href} aria-label={t("listing.removeFilter", { label: chip.label })} className="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-3 py-1 text-body-sm hover:bg-subtle">
                {chip.label}
                <X className="size-3.5" />
              </Link>
            ))}
          </div>
        ) : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {!error && products.length === 0 ? <EmptyState title={t("listing.empty")} body={t("listing.emptyHint")} /> : null}
        {products.length > 0 ? <ProductGrid products={products.map(cardFromApi)} /> : null}
        {last > 1 ? (
          <nav className="mt-6 flex items-center justify-center gap-4 text-body" aria-label="Pagination">
            {page > 1 ? <Link href={pageHref(page - 1)} className="rounded-md border border-line bg-surface px-3 py-1.5">{t("listing.prev")}</Link> : null}
            <span className="text-secondary">{t("listing.page", { page, last })}</span>
            {page < last ? <Link href={pageHref(page + 1)} className="rounded-md border border-line bg-surface px-3 py-1.5">{t("listing.next")}</Link> : null}
          </nav>
        ) : null}
      </div>
    </div>
  );
}

/** Sidebar tree for a category page: all roots, or the current root with its children. */
export function categoryTree(market: Market, roots: Category[], path: string[]): TreeNode[] {
  const root = roots.find((c) => c.slug === path[0]);
  if (!root) return roots.map((c) => ({ label: c.name, href: `/${market.slug}/${c.slug}`, active: false, depth: 0 }));
  return [
    { label: root.name, href: `/${market.slug}/${root.slug}`, active: path.length === 1, depth: 0 },
    ...(root.children ?? []).map((child) => ({ label: child.name, href: `/${market.slug}/${root.slug}/${child.slug}`, active: path[1] === child.slug, depth: 1 })),
  ];
}

/**
 * Sidebar tree with product counts from `categories/tree` (G-W4): the root of
 * the current lineage, its children, and the children of the active branch.
 * `lineage` is the breadcrumb slugs, root first.
 */
export function categoryTreeWithCounts(market: Market, nodes: CategoryNode[], lineage: string[]): TreeNode[] {
  if (lineage.length === 0) return nodes.map((n) => ({ label: n.name, href: `/${market.slug}/${n.slug}`, active: false, depth: 0, count: n.product_count }));
  const root = nodes.find((n) => n.slug === lineage[0]);
  if (!root) return [];
  const href = (slug: string) => `/${market.slug}/${slug === root.slug ? slug : `${root.slug}/${slug}`}`;
  const out: TreeNode[] = [{ label: root.name, href: href(root.slug), active: lineage.length === 1, depth: 0, count: root.product_count }];
  const walk = (children: CategoryNode[], depth: number) => {
    for (const child of children) {
      const onPath = lineage[depth] === child.slug;
      out.push({ label: child.name, href: href(child.slug), active: lineage.at(-1) === child.slug, depth, count: child.product_count });
      if (onPath && child.children.length > 0 && depth < 3) walk(child.children, depth + 1);
    }
  };
  walk(root.children, 1);
  return out;
}
