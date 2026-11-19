import clsx from "clsx";
import { Check, ChevronDown, Star, X } from "lucide-react";
import { cookies } from "next/headers";
import Link from "next/link";
import type { ReactNode } from "react";
import { SponsoredBrandRow, sponsoredBrandAds } from "@/features/page-builder/blocks/category-promos";
import { SearchChips } from "@/features/search/search-chips";
import { Alert, EmptyState } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { getFacets, getProducts, type ProductFilters } from "@/lib/api/endpoints";
import type { Category, CategoryNode, Facets } from "@/lib/api/schemas";
import { getSellerFacets, getSellerProducts } from "@/lib/api/sellers";
import { formatAmount, humanize } from "@/lib/format";
import { getT, type ServerT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { VISITOR_COOKIE } from "@/lib/session";
import { BrandFacet } from "./brand-facet";
import { FilterDrawer } from "./filter-drawer";
import { FilterForm } from "./filter-form";
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
    per_page: 60,
    attr: Object.keys(attr).length ? attr : undefined,
    fulfilment: oneOf(query.fulfilment, FULFILMENT_KEYS),
    arrives: ARRIVES_KEYS.has(query.arrives ?? "") ? query.arrives : undefined,
    condition: oneOf(query.condition, CONDITION_KEYS),
    grade: oneOf(query.grade, GRADE_KEYS),
    deals: query.deals === "1" ? "1" : undefined,
    price_drop: query.price_drop === "1" ? "1" : undefined,
    new_arrivals: query.new_arrivals === "1" ? "1" : undefined,
  };
}

/** Comma list limited to known values (`fbm,fbn`). */
function oneOf(value: string | undefined, allowed: Set<string>): string | undefined {
  const values = [...new Set((value ?? "").split(",").filter((v) => allowed.has(v)))];
  return values.length ? values.join(",") : undefined;
}

export type TreeNode = { label: string; href: string; active: boolean; depth: number; count?: number; slug?: string };

const FULFILMENT_KEYS = new Set(["supermall", "fbn", "fbm"]);
const ARRIVES_KEYS = new Set(["hour", "tomorrow", "two_days"]);
const CONDITION_KEYS = new Set(["new", "refurbished", "used"]);
const GRADE_KEYS = new Set(["A", "B", "C"]);
const SIZE_KEYS = new Set(["size", "shoe_size", "ring_size"]);
const COLOUR_KEYS = new Set(["colour", "color"]);
const GENDER_KEYS = new Set(["gender"]);
/** Every key a listing URL may carry (besides `attr[…]`). */
const KEPT = ["q", "sort", "brand", "seller", "min_price", "max_price", "rating", "fulfilment", "arrives", "condition", "grade", "deals", "price_drop", "new_arrivals"] as const;

/** "1,234" below 10,000, then a floored compact count: "12K+", "100K+", "1.2M+". */
export function resultCount(total: number, locale: string): string {
  if (total < 10_000) return total.toLocaleString(locale);
  const step = total >= 1_000_000 ? 100_000 : total >= 100_000 ? 10_000 : 1_000;
  return `${new Intl.NumberFormat(locale, { notation: "compact", maximumFractionDigits: 1 }).format(Math.floor(total / step) * step)}+`;
}

/**
 * Product listing zone (block 32 `product_listing`; design
 * category-page-target.webp / Search results 67:8168). The filter rail runs
 * beside everything in the main column: `lead` (category promos above the
 * results), the results header + sort, quick chips, the grid with
 * sponsored products ("Ad" labels from SponsoredRanker) and sponsored brand
 * rows every 4 rows, infinite scroll, and `after` (SEO text, popular
 * searches). Rail groups, each counted with every other filter applied and
 * shown only when they have data: Fulfilled by, Arrives by, Category,
 * Brand, Price, Deals, Price drop, Rating, Size, New arrivals, Item
 * condition, Grade, Colour, Seller, Gender and other variant attributes.
 * On mobile the rail is a filter sheet.
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
  lead,
  after,
  heading,
  subcategories = [],
}: {
  market: Market;
  query: ListingQuery;
  basePath: string;
  category?: string | null;
  tree?: TreeNode[];
  source?: ListingSource;
  /** Blocks above the results header (category promos), beside the rail. */
  lead?: ReactNode;
  /** Blocks under the results (SEO text, popular searches), beside the rail. */
  after?: ReactNode;
  /** Results header subject: “Electronics in UAE” (else the search term). */
  heading?: string | null;
  /** Sub-category chips of the quick filter row. */
  subcategories?: { label: string; href: string }[];
}) {
  const t = getT(market.locale);
  const filters = { ...listingFilters(query), category: category ?? undefined };
  const visitor = (await cookies()).get(VISITOR_COOKIE)?.value;
  const visitorHeaders = visitor ? { "X-Visitor-Token": visitor } : undefined;
  const sponsoredRows = source.kind === "products" && (category || filters.q);

  let result: Awaited<ReturnType<typeof getProducts>> | null = null;
  let error: string | null = null;
  const [productsResult, facetsResult, bannerAds, videoAds] = await Promise.all([
    (source.kind === "seller" ? getSellerProducts(market.slug, source.slug, filters, visitorHeaders) : getProducts(market.slug, filters, visitorHeaders)).then(
      (value) => ({ status: "fulfilled" as const, value }),
      (reason: unknown) => ({ status: "rejected" as const, reason }),
    ),
    (source.kind === "seller" ? getSellerFacets(market.slug, source.slug, filters) : getFacets(market.slug, filters)).then(
      (value) => value.data,
      () => null,
    ),
    sponsoredRows ? sponsoredBrandAds(market, "sponsored_brand_banner", category ?? null) : Promise.resolve([]),
    sponsoredRows ? sponsoredBrandAds(market, "sponsored_brand_video", category ?? null) : Promise.resolve([]),
  ]);
  if (productsResult.status === "fulfilled") result = productsResult.value;
  else error = productsResult.reason instanceof ApiError && productsResult.reason.status === 422 ? productsResult.reason.message : t("common.unreachable");
  const facets: Facets | null = facetsResult;
  const showSellers = source.kind !== "seller";

  const products = result?.data ?? [];
  const total = Number(result?.meta.total ?? products.length);
  const page = Number(result?.meta.page ?? 1);
  const last = Number(result?.meta.last_page ?? 1);

  // Every active filter, as kept in the URL.
  const keep: Record<string, string> = {};
  for (const key of KEPT) {
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
  const flag = (key: string) => (keep[key] ? without(key) : hrefWith({ ...keep, [key]: "1" }));
  const selected = (key: string) => new Set((keep[key] ?? "").split(",").filter(Boolean));

  const fulfilmentLabel = (value: string) => (FULFILMENT_KEYS.has(value) ? t(`categoryPage.fulfilment.${value as "supermall" | "fbn" | "fbm"}`) : humanize(value));
  const fulfilmentHint = (value: string) => (FULFILMENT_KEYS.has(value) ? t(`categoryPage.fulfilment.${value as "supermall" | "fbn" | "fbm"}Hint`) : null);
  const arrivesLabel = (value: string) => (ARRIVES_KEYS.has(value) ? t(`categoryPage.arrives.${value as "hour" | "tomorrow" | "two_days"}`) : humanize(value));
  const conditionLabel = (value: string) => (CONDITION_KEYS.has(value) ? t(`storefront.listing.condition.${value as "new" | "refurbished" | "used"}`) : humanize(value));
  const attributeLabel = (key: string) =>
    SIZE_KEYS.has(key) && key === "size" ? t("storefront.listing.size") : COLOUR_KEYS.has(key) ? t("storefront.listing.colour") : GENDER_KEYS.has(key) ? t("categoryPage.gender") : key === "storage" ? t("categoryPage.storage") : humanize(key);
  const money = (amount: string) => `${market.country.currency} ${formatAmount(amount)}`;

  // Active filter chips (with ×).
  const chips: { label: string; href: string }[] = [];
  for (const value of selected("fulfilment")) chips.push({ label: fulfilmentHint(value) ?? fulfilmentLabel(value), href: without("fulfilment", value) });
  if (keep.arrives) chips.push({ label: arrivesLabel(keep.arrives), href: without("arrives") });
  for (const slug of selected("brand")) chips.push({ label: facets?.brands.find((b) => b.slug === slug)?.name ?? slug, href: without("brand", slug) });
  for (const slug of selected("seller")) chips.push({ label: facets?.sellers.find((s) => s.slug === slug)?.name ?? slug, href: without("seller", slug) });
  if (keep.deals) chips.push({ label: t("storefront.listing.deals"), href: without("deals") });
  if (keep.price_drop) chips.push({ label: t("categoryPage.priceDrop"), href: without("price_drop") });
  if (keep.new_arrivals) chips.push({ label: t("storefront.listing.newArrivals"), href: without("new_arrivals") });
  if (keep.rating) chips.push({ label: t("listing.ratingUp", { stars: keep.rating }), href: without("rating") });
  for (const value of selected("condition")) chips.push({ label: conditionLabel(value), href: without("condition", value) });
  for (const value of selected("grade")) chips.push({ label: t("categoryPage.gradeValue", { grade: value }), href: without("grade", value) });
  if (keep.min_price || keep.max_price) {
    chips.push({ label: `${market.country.currency} ${keep.min_price ?? "0"} – ${keep.max_price ?? "∞"}`, href: hrefWith(Object.fromEntries(Object.entries(keep).filter(([k]) => k !== "min_price" && k !== "max_price"))) });
  }
  for (const [key, value] of Object.entries(filters.attr ?? {})) {
    for (const v of value.split(",")) chips.push({ label: `${attributeLabel(key)}: ${v}`, href: without(`attr[${key}]`, v) });
  }

  const attributes = facets?.attributes ?? [];
  const sizeFacet = attributes.find((a) => SIZE_KEYS.has(a.key));
  const brands = facets?.brands ?? [];
  const bands = priceBands(facets?.price.min?.amount, facets?.price.max?.amount);

  // Quick filter row (design: Deals ▾ Brand ▾ Price ▾ Size: XL · brands · sub-categories).
  const menus: { label: string; active: boolean; items: { label: string; href: string; active: boolean }[] }[] = [];
  if ((facets?.deals ?? 0) > 0 || (facets?.price_drop ?? 0) > 0 || keep.deals || keep.price_drop) {
    menus.push({
      label: t("categoryPage.deals"),
      active: !!(keep.deals || keep.price_drop),
      items: [
        ...((facets?.deals ?? 0) > 0 || keep.deals ? [{ label: t("categoryPage.dealsOnly"), href: flag("deals"), active: !!keep.deals }] : []),
        ...((facets?.price_drop ?? 0) > 0 || keep.price_drop ? [{ label: t("categoryPage.priceDrop"), href: flag("price_drop"), active: !!keep.price_drop }] : []),
      ],
    });
  }
  if (brands.length > 1) {
    menus.push({ label: t("categoryPage.brand"), active: selected("brand").size > 0, items: brands.slice(0, 12).map((b) => ({ label: b.name, href: toggled("brand", b.slug), active: selected("brand").has(b.slug) })) });
  }
  if (bands.length > 0) {
    const priceless = Object.fromEntries(Object.entries(keep).filter(([k]) => k !== "min_price" && k !== "max_price"));
    menus.push({
      label: t("categoryPage.price"),
      active: !!(keep.min_price || keep.max_price),
      items: [
        { label: t("categoryPage.anyPrice"), href: hrefWith(priceless), active: !keep.min_price && !keep.max_price },
        ...bands.map((band) => ({
          label: band.min === null ? t("categoryPage.under", { price: money(band.max!) }) : band.max === null ? t("categoryPage.over", { price: money(band.min) }) : t("categoryPage.between", { min: money(band.min), max: money(band.max) }),
          href: hrefWith({ ...priceless, ...(band.min ? { min_price: band.min } : {}), ...(band.max ? { max_price: band.max } : {}) }),
          active: (keep.min_price ?? null) === band.min && (keep.max_price ?? null) === band.max,
        })),
      ],
    });
  }
  const quick: { label: string; href: string; active: boolean }[] = [];
  if (sizeFacet) {
    const attrKey = `attr[${sizeFacet.key}]`;
    for (const v of sizeFacet.values.slice(0, 4)) quick.push({ label: `${attributeLabel(sizeFacet.key)}: ${v.value}`, href: toggled(attrKey, v.value), active: selected(attrKey).has(v.value) });
  }
  for (const b of brands.slice(0, 3)) quick.push({ label: b.name, href: toggled("brand", b.slug), active: selected("brand").has(b.slug) });

  const rail = sidebar({
    t,
    market,
    facets,
    keep,
    baseParams,
    basePathname,
    tree: mergeCounts(tree, facets),
    showSellers,
    fulfilmentLabel,
    fulfilmentHint,
    arrivesLabel,
    conditionLabel,
    attributeLabel,
    clearHref: chips.length > 0 ? hrefWith(keep.q ? { q: keep.q } : {}) : null,
  });

  // Remount the infinite list (and the rail form) whenever the filters change.
  const listKey = JSON.stringify({ ...keep, page });
  const totalLabel = resultCount(total, market.locale);
  const subject = heading ?? keep.q ?? null;
  const inserts = [...bannerAds, ...videoAds].map((ad, i) => <SponsoredBrandRow key={`ad-${i}`} ad={ad} market={market} />);

  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
      <aside className="hidden lg:sticky lg:top-4 lg:block lg:w-60 lg:shrink-0">
        <div key={listKey} className="rounded-lg bg-surface p-4">
          {rail}
        </div>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col gap-6">
        {lead}
        <section id="results" className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-body">
              {subject ? (
                <span>
                  <span className="text-secondary">{t("categoryPage.resultsFor", { count: totalLabel })} </span>
                  <span className="font-semibold">{market.locale === "ar" ? `«${subject}»` : `“${subject}”`}</span>
                </span>
              ) : (
                <span className="font-semibold">{t("listing.results", { count: total })}</span>
              )}
            </h2>
            <div className="flex items-center gap-2">
              <div className="lg:hidden">
                <FilterDrawer count={chips.length}>
                  <div key={listKey}>{rail}</div>
                </FilterDrawer>
              </div>
              <SortSelect value={keep.sort ?? "recommended"} basePath={basePathname} params={{ ...baseParams, ...keep }} />
            </div>
          </div>
          {menus.length > 0 || quick.length > 0 || subcategories.length > 0 ? (
            <nav className="flex flex-wrap items-center gap-2" aria-label={t("storefront.listing.quickFilters")}>
              {menus.map((menu) => (
                <details key={menu.label} className="group relative">
                  <summary className={clsx("inline-flex cursor-pointer list-none items-center gap-1 rounded-full border px-3 py-1.5 text-body-sm [&::-webkit-details-marker]:hidden", menu.active ? "border-ink bg-ink font-semibold text-white" : "border-line bg-surface hover:bg-subtle")}>
                    {menu.label}
                    <ChevronDown className="size-3.5 transition-transform group-open:rotate-180" />
                  </summary>
                  <ul className="absolute start-0 top-full z-20 mt-1 flex max-h-72 min-w-48 flex-col overflow-y-auto rounded-md border border-line bg-surface py-1 shadow-lg">
                    {menu.items.map((item) => (
                      <li key={item.label}>
                        <Link href={item.href} className={clsx("flex items-center justify-between gap-3 px-3 py-1.5 text-body-sm hover:bg-subtle", item.active && "font-semibold")}>
                          {item.label}
                          {item.active ? <Check className="size-3.5" /> : null}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </details>
              ))}
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
              {subcategories.map((chip) => (
                <Link key={chip.href} href={chip.href} className="inline-flex shrink-0 items-center rounded-full border border-line bg-surface px-3 py-1.5 text-body-sm hover:bg-subtle">
                  {chip.label}
                </Link>
              ))}
            </nav>
          ) : null}
          {chips.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2" aria-label={t("listing.activeFilters")}>
              {chips.map((chip) => (
                <Link key={chip.href + chip.label} href={chip.href} aria-label={t("listing.removeFilter", { label: chip.label })} className="inline-flex items-center gap-1 rounded-full bg-brand-subtle px-3 py-1 text-body-sm hover:bg-brand">
                  {chip.label}
                  <X className="size-3.5" />
                </Link>
              ))}
              <Link href={hrefWith(keep.q ? { q: keep.q } : {})} className="text-body-sm text-link">
                {t("listing.clear")}
              </Link>
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
              totalLabel={totalLabel}
              nextHref={page < last ? pageHref(page + 1) : null}
              firstHref={page > 1 ? pageHref(1) : null}
              inserts={inserts}
            />
          ) : null}
        </section>
        {after}
      </div>
    </div>
  );
}

/** Up to 4 round price bands between the facet's min and max: under A, A–B, B–C, over C. */
export function priceBands(min?: string | null, max?: string | null): { min: string | null; max: string | null }[] {
  const lo = Number(min);
  const hi = Number(max);
  if (!Number.isFinite(lo) || !Number.isFinite(hi) || hi <= lo || hi <= 0) return [];
  const nice = (value: number) => {
    const magnitude = 10 ** Math.floor(Math.log10(Math.max(value, 1)));
    const step = magnitude / 2;
    return Math.max(1, Math.round(value / step) * step);
  };
  const cuts = [...new Set([0.15, 0.35, 0.65].map((f) => nice(lo + (hi - lo) * f)))].filter((c) => c > lo && c < hi).sort((a, b) => a - b);
  if (cuts.length === 0) return [];
  const out: { min: string | null; max: string | null }[] = [{ min: null, max: String(cuts[0]) }];
  for (let i = 1; i < cuts.length; i++) out.push({ min: String(cuts[i - 1]), max: String(cuts[i]) });
  out.push({ min: String(cuts.at(-1)), max: null });
  return out;
}

/** Tree counts with every other filter applied (facets.categories), when the facets have them. */
function mergeCounts(tree: TreeNode[] | undefined, facets: Facets | null): TreeNode[] | undefined {
  if (!tree || !facets || facets.categories.length === 0) return tree;
  const counts = new Map(facets.categories.map((c) => [c.slug, c.count]));
  return tree.map((node) => (node.slug ? { ...node, count: counts.get(node.slug) ?? 0 } : node)).filter((node) => node.active || node.depth === 0 || node.count !== 0);
}

function sidebar({
  t,
  market,
  facets,
  keep,
  baseParams,
  basePathname,
  tree,
  showSellers,
  fulfilmentLabel,
  fulfilmentHint,
  arrivesLabel,
  conditionLabel,
  attributeLabel,
  clearHref,
}: {
  t: ServerT;
  market: Market;
  facets: Facets | null;
  keep: Record<string, string>;
  baseParams: Record<string, string>;
  basePathname: string;
  tree?: TreeNode[];
  showSellers: boolean;
  fulfilmentLabel: (value: string) => string;
  fulfilmentHint: (value: string) => string | null;
  arrivesLabel: (value: string) => string;
  conditionLabel: (value: string) => string;
  attributeLabel: (key: string) => string;
  clearHref: string | null;
}) {
  const selected = (key: string) => new Set((keep[key] ?? "").split(",").filter(Boolean));
  let groupIndex = 0;
  const group = (legend: string, active: boolean, body: ReactNode) => {
    const open = active || groupIndex++ < 4;
    return (
      <details open={open} className="group border-b border-line pb-3 last-of-type:border-0">
        <summary className="flex cursor-pointer list-none items-center justify-between gap-2 text-label font-semibold [&::-webkit-details-marker]:hidden">
          {legend}
          <ChevronDown className="size-4 text-muted transition-transform group-open:rotate-180" />
        </summary>
        <div className="mt-2">{body}</div>
      </details>
    );
  };
  const checkboxGroup = (name: string, legend: string, rows: { value: string; label: ReactNode; count: number }[]) => {
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
                <input type="checkbox" name={name} value={row.value} defaultChecked={active.has(row.value)} className="size-4 shrink-0 accent-brand" />
                <span className="min-w-0 flex-1">{row.label}</span>
                <span className="text-caption text-muted tabular-nums">{row.count}</span>
              </label>
            </li>
          ))}
        </ul>
      </fieldset>,
    );
  };
  const flagGroup = (name: string, legend: string, label: string, count: number) =>
    count > 0 || keep[name]
      ? group(
          legend,
          !!keep[name],
          <label className="flex items-center gap-2 text-body-sm">
            <input type="checkbox" name={name} value="1" defaultChecked={!!keep[name]} className="size-4 accent-brand" />
            <span className="flex-1">{label}</span>
            <span className="text-caption text-muted tabular-nums">{count}</span>
          </label>,
        )
      : null;

  const attributes = facets?.attributes ?? [];
  const attributeGroup = (attribute: (typeof attributes)[number]) => (
    <div key={attribute.key} className="contents">
      {checkboxGroup(`attr[${attribute.key}]`, attributeLabel(attribute.key), attribute.values.map((v) => ({ value: v.value, label: v.value, count: v.count })))}
    </div>
  );
  const sizeAttrs = attributes.filter((a) => SIZE_KEYS.has(a.key));
  const colourAttrs = attributes.filter((a) => COLOUR_KEYS.has(a.key));
  const genderAttrs = attributes.filter((a) => GENDER_KEYS.has(a.key));
  const otherAttrs = attributes.filter((a) => !SIZE_KEYS.has(a.key) && !COLOUR_KEYS.has(a.key) && !GENDER_KEYS.has(a.key));
  const priceMin = facets?.price.min?.amount;
  const priceMax = facets?.price.max?.amount;
  const step = market.country.decimals >= 3 ? "0.001" : "0.01";

  return (
    <FilterForm action={basePathname} className="flex flex-col gap-3">
      {Object.entries(baseParams).map(([k, v]) => (
        <input key={k} type="hidden" name={k} value={v} />
      ))}
      {keep.q ? <input type="hidden" name="q" value={keep.q} /> : null}
      {keep.sort ? <input type="hidden" name="sort" value={keep.sort} /> : null}
      {checkboxGroup(
        "fulfilment",
        t("storefront.listing.fulfilledBy"),
        (facets?.fulfilment ?? []).map((f) => ({
          value: f.value,
          count: f.count,
          label: (
            <span className="flex flex-col">
              <span className={clsx("w-fit rounded-xs px-1.5 text-caption font-bold italic", f.value === "fbn" ? "bg-brand text-ink" : f.value === "supermall" ? "bg-ink text-white" : "bg-subtle text-ink")}>{fulfilmentLabel(f.value)}</span>
              {fulfilmentHint(f.value) ? <span className="text-caption text-muted">{fulfilmentHint(f.value)}</span> : null}
            </span>
          ),
        })),
      )}
      {(facets?.arrives ?? []).length > 0
        ? group(
            t("categoryPage.arrivesBy"),
            !!keep.arrives,
            <ul className="flex flex-col gap-1.5">
              {(facets?.arrives ?? []).map((a) => (
                <li key={a.value}>
                  <label className="flex items-center gap-2 text-body-sm">
                    <input type="radio" name="arrives" value={a.value} defaultChecked={keep.arrives === a.value} className="size-4 accent-brand" />
                    <span className="flex-1">{arrivesLabel(a.value)}</span>
                    <span className="text-caption text-muted tabular-nums">{a.count}</span>
                  </label>
                </li>
              ))}
            </ul>,
          )
        : null}
      {tree && tree.length > 0
        ? group(
            t("listing.category"),
            true,
            <ul className="flex max-h-72 flex-col gap-1.5 overflow-y-auto pe-1">
              {tree.map((node) => (
                <li key={node.href} style={{ paddingInlineStart: node.depth * 12 }}>
                  <Link href={node.href} aria-current={node.active ? "page" : undefined} className={clsx("flex items-center justify-between gap-2 text-body-sm", node.active ? "font-semibold text-ink" : "text-secondary hover:text-ink")}>
                    <span className="truncate">
                      {node.depth > 0 && node.active ? "› " : ""}
                      {node.label}
                    </span>
                    {node.count != null ? <span className="text-caption font-normal text-muted tabular-nums">{node.count}</span> : null}
                  </Link>
                </li>
              ))}
            </ul>,
          )
        : null}
      {(facets?.brands ?? []).length > 0
        ? group(t("listing.brand"), selected("brand").size > 0, <BrandFacet rows={(facets?.brands ?? []).map((b) => ({ value: b.slug, label: b.name, count: b.count }))} selected={[...selected("brand")]} />)
        : null}
      {priceMin || priceMax || keep.min_price || keep.max_price
        ? group(
            t("listing.price", { currency: market.country.currency }),
            !!(keep.min_price || keep.max_price),
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-2">
                <input name="min_price" type="number" min="0" step={step} inputMode="decimal" defaultValue={keep.min_price ?? ""} placeholder={priceMin ? formatAmount(priceMin) : t("listing.min")} aria-label={t("listing.min")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
                <span className="text-muted">–</span>
                <input name="max_price" type="number" min="0" step={step} inputMode="decimal" defaultValue={keep.max_price ?? ""} placeholder={priceMax ? formatAmount(priceMax) : t("listing.max")} aria-label={t("listing.max")} className="h-9 w-full min-w-0 rounded-md border border-line-strong bg-surface px-2 text-body-sm" />
                <button type="submit" className="h-9 shrink-0 rounded-md bg-ink px-3 text-body-sm font-semibold text-white">
                  {t("categoryPage.applyPrice")}
                </button>
              </div>
              {priceMin && priceMax ? (
                <p className="text-caption text-muted">
                  {t("listing.min")} {formatAmount(priceMin)} — {t("listing.max")} {formatAmount(priceMax)}
                </p>
              ) : null}
            </div>,
          )
        : null}
      {flagGroup("deals", t("storefront.listing.deals"), t("storefront.listing.dealsOnly"), facets?.deals ?? 0)}
      {flagGroup("price_drop", t("categoryPage.priceDrop"), t("categoryPage.priceDropHint"), facets?.price_drop ?? 0)}
      {facets && facets.ratings.some((r) => r.count > 0)
        ? group(
            t("listing.rating"),
            !!keep.rating,
            <ul className="flex flex-col gap-1.5">
              {facets.ratings
                .filter((r) => r.count > 0)
                .map((r) => (
                  <li key={r.min}>
                    <label className="flex items-center gap-2 text-body-sm">
                      <input type="radio" name="rating" value={r.min} defaultChecked={keep.rating === String(r.min)} className="size-4 accent-brand" />
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
      {flagGroup("new_arrivals", t("storefront.listing.newArrivals"), t("categoryPage.newArrivalsHint"), facets?.new_arrivals ?? 0)}
      {checkboxGroup("condition", t("storefront.listing.itemCondition"), (facets?.conditions ?? []).map((c) => ({ value: c.value, label: conditionLabel(c.value), count: c.count })))}
      {checkboxGroup("grade", t("categoryPage.grade"), (facets?.grades ?? []).map((g) => ({ value: g.value, label: t("categoryPage.gradeValue", { grade: g.value }), count: g.count })))}
      {colourAttrs.map(attributeGroup)}
      {showSellers ? checkboxGroup("seller", t("listing.seller"), (facets?.sellers ?? []).filter((s) => s.slug).map((s) => ({ value: s.slug!, label: s.name ?? s.slug!, count: s.count }))) : null}
      {genderAttrs.map(attributeGroup)}
      {otherAttrs.map(attributeGroup)}
      <noscript>
        <button type="submit" className="h-9 rounded-md bg-ink px-4 text-body-sm font-semibold text-white">
          {t("listing.apply")}
        </button>
      </noscript>
      {clearHref ? (
        <Link href={clearHref} className="text-body-sm text-link">
          {t("listing.clear")}
        </Link>
      ) : null}
    </FilterForm>
  );
}

/** Sidebar tree for a category page: all roots, or the current root with its children. */
export function categoryTree(market: Market, roots: Category[], path: string[]): TreeNode[] {
  const root = roots.find((c) => c.slug === path[0]);
  if (!root) return roots.map((c) => ({ label: c.name, href: `/${market.slug}/${c.slug}`, active: false, depth: 0, slug: c.slug }));
  return [
    { label: root.name, href: `/${market.slug}/${root.slug}`, active: path.length === 1, depth: 0, slug: root.slug },
    ...(root.children ?? []).map((child) => ({ label: child.name, href: `/${market.slug}/${root.slug}/${child.slug}`, active: path[1] === child.slug, depth: 1, slug: child.slug })),
  ];
}

/**
 * Sidebar tree with product counts from `categories/tree` (G-W4): the root of
 * the current lineage, its children, and the children of the active branch.
 * `lineage` is the breadcrumb slugs, root first.
 */
export function categoryTreeWithCounts(market: Market, nodes: CategoryNode[], lineage: string[]): TreeNode[] {
  if (lineage.length === 0) return nodes.map((n) => ({ label: n.name, href: `/${market.slug}/${n.slug}`, active: false, depth: 0, count: n.product_count, slug: n.slug }));
  const root = nodes.find((n) => n.slug === lineage[0]);
  if (!root) return [];
  const href = (slug: string) => `/${market.slug}/${slug === root.slug ? slug : `${root.slug}/${slug}`}`;
  const out: TreeNode[] = [{ label: root.name, href: href(root.slug), active: lineage.length === 1, depth: 0, count: root.product_count, slug: root.slug }];
  const walk = (children: CategoryNode[], depth: number) => {
    for (const child of children) {
      const onPath = lineage[depth] === child.slug;
      out.push({ label: child.name, href: href(child.slug), active: lineage.at(-1) === child.slug, depth, count: child.product_count, slug: child.slug });
      if (onPath && child.children.length > 0 && depth < 3) walk(child.children, depth + 1);
    }
  };
  walk(root.children, 1);
  return out;
}
