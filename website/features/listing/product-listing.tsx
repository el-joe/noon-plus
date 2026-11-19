import clsx from "clsx";
import { Check, ChevronDown, SlidersHorizontal, Star, X } from "lucide-react";
import { cookies } from "next/headers";
import Link from "next/link";
import type { ReactNode } from "react";
import { SearchChips } from "@/features/search/search-chips";
import { Alert, EmptyState } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { getFacets, getProducts, type ProductFilters } from "@/lib/api/endpoints";
import type { Category, CategoryNode, Facets } from "@/lib/api/schemas";
import { getSellerFacets, getSellerProducts } from "@/lib/api/sellers";
import { formatAmount, humanize } from "@/lib/format";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { VISITOR_COOKIE } from "@/lib/session";
import { InfiniteProducts, type ListingSource } from "./infinite-products";
import { SortSelect } from "./sort-select";

export type ListingQuery = Record<string, string | undefined>;

const SORTS = new Set(["recommended", "bestselling", "newest", "price_asc", "price_desc", "rating"]);
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
    fulfilment: oneOf(query.fulfilment, FULFILMENT_KEYS),
    condition: oneOf(query.condition, CONDITION_KEYS),
    deals: query.deals === "1" ? "1" : undefined,
  };
}

/** Comma list limited to known values (`fbm,fbn`). */
function oneOf(value: string | undefined, allowed: Set<string>): string | undefined {
  const values = [...new Set((value ?? "").split(",").filter((v) => allowed.has(v)))];
  return values.length ? values.join(",") : undefined;
}

export type TreeNode = { label: string; href: string; active: boolean; depth: number; count?: number };

const FULFILMENT_KEYS = new Set(["fbm", "fbn"]);
const CONDITION_KEYS = new Set(["new", "refurbished", "used"]);
const SIZE_KEYS = new Set(["size", "shoe_size", "ring_size"]);
const COLOUR_KEYS = new Set(["colour", "color"]);

/**
 * Product listing zone (block 32 `product_listing`; Figma Category v2 40:4438 /
 * Search results 67:8168): facet rail with counts (G-W4 + QC-027: Fulfilled
 * by, category, brand, price, Deals, rating, Size, Condition, Colour, seller,
 * other variant attributes), quick chips, results header + sort, grid with
 * sponsored interleave ("Ad" labels from SponsoredRanker) and infinite scroll.
 *
 * `source` switches the data to a seller storefront (`sellers/{slug}/products`).
 */
export async function ProductListing({
  market,
  query,
  basePath,
  category,
  tree,
  source = { kind: "products" },
}: {
  market: Market;
  query: ListingQuery;
  basePath: string;
  category?: string | null;
  tree?: TreeNode[];
  source?: ListingSource;
}) {
  const t = getT(market.locale);
  const filters = { ...listingFilters(query), category: category ?? undefined };
  const visitor = (await cookies()).get(VISITOR_COOKIE)?.value;
  const visitorHeaders = visitor ? { "X-Visitor-Token": visitor } : undefined;

  let result: Awaited<ReturnType<typeof getProducts>> | null = null;
  let error: string | null = null;
  const [productsResult, facetsResult] = await Promise.allSettled(
    source.kind === "seller"
      ? [getSellerProducts(market.slug, source.slug, filters, visitorHeaders), getSellerFacets(market.slug, source.slug, filters)]
      : [getProducts(market.slug, filters, visitorHeaders), getFacets(market.slug, filters)],
  );
  if (productsResult.status === "fulfilled") result = productsResult.value;
  else error = productsResult.reason instanceof ApiError && productsResult.reason.status === 422 ? productsResult.reason.message : t("common.unreachable");
  const facets: Facets | null = facetsResult.status === "fulfilled" ? facetsResult.value.data : null;
  const showSellers = source.kind !== "seller";

  const products = result?.data ?? [];
  const total = Number(result?.meta.total ?? products.length);
  const page = Number(result?.meta.page ?? 1);
  const last = Number(result?.meta.last_page ?? 1);

  // Every active filter, as kept in the URL.
  const keep: Record<string, string> = {};
  for (const key of ["q", "sort", "brand", "seller", "min_price", "max_price", "rating", "fulfilment", "condition", "deals"] as const) {
    const v = key === "q" || key === "sort" ? query[key] : (filters as Record<string, unknown>)[key];
    if (typeof v === "string" && v) keep[key] = v;
  }
  if (!showSellers) delete keep.seller;
  for (const [key, value] of Object.entries(filters.attr ?? {})) keep[`attr[${key}]`] = value;
  // The base path may already carry a query (brand store `?category=`).
  const [basePathname, baseSearch] = basePath.split("?");
  const baseParams = Object.fromEntries(new URLSearchParams(baseSearch ?? ""));
  const hrefWith = (params: Record<string, string>) => {
    const qs = new URLSearchParams({ ...baseParams, ...params }).toString();
    return qs ? `${basePathname}?${qs}` : basePathname;
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
  const toggled = (key: string, value: string) => {
    const current = (keep[key] ?? "").split(",").filter(Boolean);
    const next = { ...keep };
    const values = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
    if (values.length) next[key] = values.join(",");
    else delete next[key];
    return hrefWith(next);
  };
  const selected = (key: string) => new Set((keep[key] ?? "").split(",").filter(Boolean));

  const fulfilmentLabel = (value: string) => (FULFILMENT_KEYS.has(value) ? t(`storefront.listing.fulfilment.${value as "fbm" | "fbn"}`) : humanize(value));
  const conditionLabel = (value: string) => (CONDITION_KEYS.has(value) ? t(`storefront.listing.condition.${value as "new" | "refurbished" | "used"}`) : humanize(value));
  const attributeLabel = (key: string) => (SIZE_KEYS.has(key) && key === "size" ? t("storefront.listing.size") : COLOUR_KEYS.has(key) ? t("storefront.listing.colour") : humanize(key));

  // Active filter chips.
  const chips: { label: string; href: string }[] = [];
  for (const value of selected("fulfilment")) chips.push({ label: fulfilmentLabel(value), href: without("fulfilment", value) });
  for (const slug of selected("brand")) chips.push({ label: facets?.brands.find((b) => b.slug === slug)?.name ?? slug, href: without("brand", slug) });
  for (const slug of selected("seller")) chips.push({ label: facets?.sellers.find((s) => s.slug === slug)?.name ?? slug, href: without("seller", slug) });
  if (keep.deals) chips.push({ label: t("storefront.listing.deals"), href: without("deals") });
  if (keep.rating) chips.push({ label: t("listing.ratingUp", { stars: keep.rating }), href: without("rating") });
  for (const value of selected("condition")) chips.push({ label: conditionLabel(value), href: without("condition", value) });
  if (keep.min_price || keep.max_price) {
    chips.push({ label: `${market.country.currency} ${keep.min_price ?? "0"} – ${keep.max_price ?? "∞"}`, href: hrefWith(Object.fromEntries(Object.entries(keep).filter(([k]) => k !== "min_price" && k !== "max_price"))) });
  }
  for (const [key, value] of Object.entries(filters.attr ?? {})) {
    for (const v of value.split(",")) chips.push({ label: `${attributeLabel(key)}: ${v}`, href: without(`attr[${key}]`, v) });
  }

  // Quick chips (Figma 40:4438, above the results): one-tap toggles.
  const sizeFacet = facets?.attributes.find((a) => SIZE_KEYS.has(a.key));
  const quick: { label: string; href: string; active: boolean }[] = [];
  if ((facets?.deals ?? 0) > 0 || keep.deals) quick.push({ label: t("storefront.listing.deals"), href: keep.deals ? without("deals") : hrefWith({ ...keep, deals: "1" }), active: !!keep.deals });
  if (facets?.fulfilment.some((f) => f.value === "fbn" && f.count > 0) || selected("fulfilment").has("fbn")) {
    quick.push({ label: t("storefront.listing.fulfilment.fbn"), href: toggled("fulfilment", "fbn"), active: selected("fulfilment").has("fbn") });
  }
  if (facets?.ratings.some((r) => r.min === 4 && r.count > 0) || keep.rating === "4") {
    quick.push({ label: t("listing.ratingUp", { stars: "4" }), href: keep.rating === "4" ? without("rating") : hrefWith({ ...keep, rating: "4" }), active: keep.rating === "4" });
  }
  quick.push({ label: t("storefront.listing.newArrivals"), href: keep.sort === "newest" ? without("sort") : hrefWith({ ...keep, sort: "newest" }), active: keep.sort === "newest" });
  if ((facets?.conditions ?? []).some((c) => c.value === "refurbished" && c.count > 0) || selected("condition").has("refurbished")) {
    quick.push({ label: t("storefront.listing.condition.refurbished"), href: toggled("condition", "refurbished"), active: selected("condition").has("refurbished") });
  }
  if (sizeFacet) {
    const attrKey = `attr[${sizeFacet.key}]`;
    for (const v of sizeFacet.values.slice(0, 6)) {
      quick.push({ label: `${attributeLabel(sizeFacet.key)} ${v.value}`, href: toggled(attrKey, v.value), active: selected(attrKey).has(v.value) });
    }
  }

  let groupIndex = 0;
  const group = (legend: string, active: boolean, body: ReactNode) => {
    const open = active || groupIndex++ < 5;
    return (
      <details open={open} className="group border-b border-line pb-4 last-of-type:border-0">
        <summary className="flex cursor-pointer list-none items-center justify-between gap-2 text-label font-semibold [&::-webkit-details-marker]:hidden">
          {legend}
          <ChevronDown className="size-4 text-muted transition-transform group-open:rotate-180" />
        </summary>
        <div className="mt-2">{body}</div>
      </details>
    );
  };

  const checkboxGroup = (name: string, legend: string, rows: { value: string; label: string; count: number }[]) => {
    if (rows.length === 0) return null;
    const active = selected(name);
    return group(
      legend,
      active.size > 0,
      <fieldset>
        <legend className="sr-only">{legend}</legend>
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
      </fieldset>,
    );
  };

  const priceMin = facets?.price.min?.amount;
  const priceMax = facets?.price.max?.amount;
  const attributes = facets?.attributes ?? [];
  const sizeAttrs = attributes.filter((a) => SIZE_KEYS.has(a.key));
  const colourAttrs = attributes.filter((a) => COLOUR_KEYS.has(a.key));
  const otherAttrs = attributes.filter((a) => !SIZE_KEYS.has(a.key) && !COLOUR_KEYS.has(a.key));
  const attributeGroup = (attribute: (typeof attributes)[number]) => (
    <div key={attribute.key} className="contents">
      {checkboxGroup(`attr[${attribute.key}]`, attributeLabel(attribute.key), attribute.values.map((v) => ({ value: v.value, label: v.value, count: v.count })))}
    </div>
  );

  const sidebar = (
    <form method="get" action={basePathname} className="flex flex-col gap-4">
      {Object.entries(baseParams).map(([k, v]) => (
        <input key={k} type="hidden" name={k} value={v} />
      ))}
      {keep.q ? <input type="hidden" name="q" value={keep.q} /> : null}
      {keep.sort ? <input type="hidden" name="sort" value={keep.sort} /> : null}
      {checkboxGroup("fulfilment", t("storefront.listing.fulfilledBy"), (facets?.fulfilment ?? []).map((f) => ({ value: f.value, label: fulfilmentLabel(f.value), count: f.count })))}
      {tree && tree.length > 0
        ? group(
            t("listing.category"),
            true,
            <ul className="flex flex-col gap-1.5">
              {tree.map((node) => (
                <li key={node.href} style={{ paddingInlineStart: node.depth * 12 }}>
                  <Link href={node.href} className={clsx("flex items-center justify-between gap-2 text-body-sm", node.active ? "font-semibold text-ink" : "text-secondary hover:text-ink")}>
                    <span className="truncate">{node.label}</span>
                    {node.count != null ? <span className="text-caption font-normal text-muted tabular-nums">{node.count}</span> : null}
                  </Link>
                </li>
              ))}
            </ul>,
          )
        : null}
      {checkboxGroup("brand", t("listing.brand"), (facets?.brands ?? []).map((b) => ({ value: b.slug, label: b.name, count: b.count })))}
      {group(
        t("listing.price", { currency: market.country.currency }),
        !!(keep.min_price || keep.max_price),
        <div className="flex items-center gap-2">
          <input name="min_price" inputMode="decimal" defaultValue={keep.min_price ?? ""} placeholder={priceMin ? formatAmount(priceMin) : t("listing.min")} aria-label={t("listing.min")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
          <span className="text-muted">–</span>
          <input name="max_price" inputMode="decimal" defaultValue={keep.max_price ?? ""} placeholder={priceMax ? formatAmount(priceMax) : t("listing.max")} aria-label={t("listing.max")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
        </div>,
      )}
      {(facets?.deals ?? 0) > 0 || keep.deals
        ? group(
            t("storefront.listing.deals"),
            !!keep.deals,
            <label className="flex items-center gap-2 text-body-sm">
              <input type="checkbox" name="deals" value="1" defaultChecked={!!keep.deals} className="size-4 accent-ink" />
              <span className="flex-1">{t("storefront.listing.dealsOnly")}</span>
              <span className="text-caption text-muted tabular-nums">{facets?.deals ?? 0}</span>
            </label>,
          )
        : null}
      {facets && facets.ratings.some((r) => r.count > 0)
        ? group(
            t("listing.rating"),
            !!keep.rating,
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
            </ul>,
          )
        : null}
      {sizeAttrs.map(attributeGroup)}
      {checkboxGroup("condition", t("storefront.listing.itemCondition"), (facets?.conditions ?? []).map((c) => ({ value: c.value, label: conditionLabel(c.value), count: c.count })))}
      {colourAttrs.map(attributeGroup)}
      {showSellers ? checkboxGroup("seller", t("listing.seller"), (facets?.sellers ?? []).filter((s) => s.slug).map((s) => ({ value: s.slug!, label: s.name ?? s.slug!, count: s.count }))) : null}
      {otherAttrs.map(attributeGroup)}
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

  // Remount the infinite list whenever the filters change.
  const listKey = JSON.stringify({ ...keep, page });

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
          <SortSelect value={keep.sort ?? "recommended"} basePath={basePathname} params={{ ...baseParams, ...keep }} />
        </div>
        {quick.length > 0 ? (
          <nav className="scrollbar-none -mx-1 mb-3 flex gap-2 overflow-x-auto px-1 pb-1" aria-label={t("storefront.listing.quickFilters")}>
            {quick.map((chip) => (
              <Link
                key={chip.label}
                href={chip.href}
                aria-pressed={chip.active}
                className={clsx("inline-flex shrink-0 items-center gap-1 rounded-full border px-3 py-1.5 text-body-sm", chip.active ? "border-ink bg-ink font-semibold text-white" : "border-line bg-surface hover:bg-subtle")}
              >
                {chip.active ? <Check className="size-3.5" /> : null}
                {chip.label}
              </Link>
            ))}
          </nav>
        ) : null}
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
        {!error && products.length === 0 && filters.q ? <SearchChips /> : null}
        {products.length > 0 ? (
          <InfiniteProducts
            key={listKey}
            initial={products}
            filters={{ ...filters, page: undefined }}
            source={source}
            page={page}
            last={last}
            total={total}
            nextHref={page < last ? pageHref(page + 1) : null}
            firstHref={page > 1 ? pageHref(1) : null}
          />
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
