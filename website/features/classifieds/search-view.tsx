import { ChevronRight, Home, SearchX } from "lucide-react";
import Link from "next/link";
import { buttonClass } from "@/components/ui/button";
import { Alert, EmptyState } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { CategoryAttributes } from "@/lib/api/classifieds";
import { ApiError } from "@/lib/api/client";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { AdGrid } from "./ad-card";
import { findCategory, loadClassifiedCategories } from "./data";
import { ClassifiedSortSelect, SaveSearchButton } from "./search-tools";

const inputClass = "h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-body";

/** `/classified/search` — results with category, price, attribute filters (Figma Classifieds results 54:1139). */
export async function ClassifiedSearch({ market, params }: { market: Market; params: Record<string, string> }) {
  const t = getT(market.locale);
  const base = `/${market.slug}/classified`;
  const { tree, propertySlugs } = await loadClassifiedCategories(market.slug);
  const current = findCategory(tree, params.category);
  const attributes: CategoryAttributes | null = current
    ? await api
        .getCategoryAttributes(market.slug, current.node.slug)
        .then((r) => r.data)
        .catch(() => null)
    : null;
  const filterable = (attributes?.attributes ?? []).filter((a) => a.filterable);
  const page = Math.max(1, Number(params.page) || 1);
  // Verticals present in the tree (inherited `vertical` on every node).
  const verticals = [...new Set(api.flattenCategories(tree).map((c) => c.vertical).filter((v): v is string => !!v))];
  const vertical = params.vertical || current?.node.vertical || null;
  const showPropertyFilters = vertical === "property";
  const attr = Object.fromEntries(Object.entries(params).filter(([k]) => k.startsWith("attr[")));

  let error: string | null = null;
  const result = await api
    .getAds(market.slug, {
      q: params.q,
      category: params.category,
      kind: params.kind,
      city: params.city,
      min_price: params.min_price,
      max_price: params.max_price,
      verified: params.verified === "1" ? "1" : undefined,
      with_video: params.with_video === "1" ? "1" : undefined,
      vertical: params.vertical || undefined,
      bathrooms_min: /^\d+$/.test(params.bathrooms_min ?? "") ? params.bathrooms_min : undefined,
      min_area: /^\d+(\.\d+)?$/.test(params.min_area ?? "") ? params.min_area : undefined,
      max_area: /^\d+(\.\d+)?$/.test(params.max_area ?? "") ? params.max_area : undefined,
      sort: params.sort,
      page,
      per_page: 24,
      attr,
    })
    .catch((e: unknown) => {
      error = e instanceof ApiError && e.status === 422 ? t("classifieds.invalidFilters") : t("common.error");
      return null;
    });
  const ads = result?.data ?? [];
  const total = Number(result?.meta.total ?? ads.length);
  const lastPage = Number(result?.meta.last_page ?? 1);
  const title = current?.node.name ?? (params.kind === "wanted" ? t("classifieds.home.wantedAds") : params.q ? t("classifieds.resultsFor", { q: params.q }) : t("classifieds.allAds"));
  const pageHref = (n: number) => `${base}/search?${new URLSearchParams({ ...params, page: String(n) }).toString()}`;

  return (
    <div className="flex flex-col gap-4">
      <nav className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary" aria-label={t("common.breadcrumb")}>
        <Link href={`/${market.slug}`} aria-label={t("common.home")}>
          <Home className="size-3.5" />
        </Link>
        <ChevronRight className="size-3.5 rtl:rotate-180" />
        <Link href={base} className="hover:text-ink">
          {t("classifieds.title")}
        </Link>
        {(current?.trail ?? []).map((c) => (
          <span key={c.slug} className="flex items-center gap-1.5">
            <ChevronRight className="size-3.5 rtl:rotate-180" />
            <Link href={`${base}/search?category=${c.slug}`} className="hover:text-ink">
              {c.name}
            </Link>
          </span>
        ))}
      </nav>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-h2 font-bold">{title}</h1>
          <p className="text-body-sm text-secondary">{t("classifieds.resultCount", { count: total })}</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <SaveSearchButton params={params} defaultName={title} />
          <ClassifiedSortSelect value={params.sort ?? "recommended"} basePath={`${base}/search`} params={params} />
        </div>
      </div>

      {current && current.node.children.length > 0 ? (
        <div className="scrollbar-none flex gap-2 overflow-x-auto">
          {current.node.children.map((child) => (
            <Link key={child.slug} href={`${base}/search?category=${child.slug}`} className="shrink-0 rounded-full border border-line bg-surface px-3 py-1 text-body-sm hover:bg-subtle">
              {child.name}
            </Link>
          ))}
        </div>
      ) : null}

      <div className="grid items-start gap-6 lg:grid-cols-[260px_1fr]">
        <form action={`${base}/search`} className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-4">
          <h2 className="text-h4 font-semibold">{t("classifieds.filters")}</h2>
          <label className="flex flex-col gap-1 text-label font-medium">
            {t("classifieds.keyword")}
            <input name="q" defaultValue={params.q} className={inputClass} />
          </label>
          {verticals.length > 1 ? (
            <label className="flex flex-col gap-1 text-label font-medium">
              {t("classifieds.vertical")}
              <select name="vertical" defaultValue={params.vertical ?? ""} className={inputClass}>
                <option value="">{t("classifieds.any")}</option>
                {verticals.map((v) => (
                  <option key={v} value={v}>
                    {verticalLabel(t, v)}
                  </option>
                ))}
              </select>
            </label>
          ) : null}
          <label className="flex flex-col gap-1 text-label font-medium">
            {t("classifieds.category")}
            <select name="category" defaultValue={params.category ?? ""} className={inputClass}>
              <option value="">{t("classifieds.allCategories")}</option>
              {api.flattenCategories(tree).map((c) => (
                <option key={c.slug} value={c.slug}>
                  {" ".repeat(c.depth * 3)}
                  {c.name}
                </option>
              ))}
            </select>
          </label>
          <fieldset className="flex flex-col gap-1">
            <legend className="mb-1 text-label font-medium">{t("classifieds.adType")}</legend>
            {(["", "offer", "wanted"] as const).map((kind) => (
              <label key={kind || "all"} className="flex items-center gap-2 text-body">
                <input type="radio" name="kind" value={kind} defaultChecked={(params.kind ?? "") === kind} />
                {kind === "" ? t("classifieds.any") : t(`classifieds.kinds.${kind}`)}
              </label>
            ))}
          </fieldset>
          <label className="flex flex-col gap-1 text-label font-medium">
            {t("classifieds.city")}
            <input name="city" defaultValue={params.city} className={inputClass} />
          </label>
          <div className="flex flex-col gap-1">
            <span className="text-label font-medium">{t("classifieds.price", { currency: market.country.currency })}</span>
            <div className="flex gap-2">
              <input name="min_price" type="number" min={0} placeholder={t("classifieds.min")} defaultValue={params.min_price} className={inputClass} />
              <input name="max_price" type="number" min={0} placeholder={t("classifieds.max")} defaultValue={params.max_price} className={inputClass} />
            </div>
          </div>
          {showPropertyFilters ? (
            <>
              <label className="flex flex-col gap-1 text-label font-medium">
                {t("classifieds.bathrooms")}
                <select name="bathrooms_min" defaultValue={params.bathrooms_min ?? ""} className={inputClass}>
                  <option value="">{t("classifieds.any")}</option>
                  {[1, 2, 3, 4].map((n) => (
                    <option key={n} value={n}>
                      {t("classifieds.bathroomsMin", { count: n })}
                    </option>
                  ))}
                </select>
              </label>
              <div className="flex flex-col gap-1">
                <span className="text-label font-medium">{t("classifieds.area")}</span>
                <div className="flex gap-2">
                  <input name="min_area" type="number" min={0} placeholder={t("classifieds.min")} defaultValue={params.min_area} className={inputClass} />
                  <input name="max_area" type="number" min={0} placeholder={t("classifieds.max")} defaultValue={params.max_area} className={inputClass} />
                </div>
              </div>
            </>
          ) : null}
          {filterable.map((a) => {
            const label = a.label ?? a.key;
            if (a.type === "select" || a.type === "multiselect") {
              return (
                <label key={a.key} className="flex flex-col gap-1 text-label font-medium">
                  {label}
                  <select name={`attr[${a.key}]`} defaultValue={params[`attr[${a.key}]`] ?? ""} className={inputClass}>
                    <option value="">{t("classifieds.any")}</option>
                    {a.options.map((o) => (
                      <option key={api.optionValue(o)} value={api.optionValue(o)}>
                        {api.optionLabel(o, market.locale)}
                      </option>
                    ))}
                  </select>
                </label>
              );
            }
            if (a.type === "number" || a.type === "year") {
              return (
                <div key={a.key} className="flex flex-col gap-1">
                  <span className="text-label font-medium">
                    {label}
                    {a.unit ? ` (${a.unit})` : ""}
                  </span>
                  <div className="flex gap-2">
                    <input name={`attr[${a.key}][min]`} type="number" placeholder={t("classifieds.min")} defaultValue={params[`attr[${a.key}][min]`]} className={inputClass} />
                    <input name={`attr[${a.key}][max]`} type="number" placeholder={t("classifieds.max")} defaultValue={params[`attr[${a.key}][max]`]} className={inputClass} />
                  </div>
                </div>
              );
            }
            if (a.type === "boolean") {
              return (
                <label key={a.key} className="flex items-center gap-2 text-body">
                  <input type="checkbox" name={`attr[${a.key}]`} value="1" defaultChecked={params[`attr[${a.key}]`] === "1"} />
                  {label}
                </label>
              );
            }
            return (
              <label key={a.key} className="flex flex-col gap-1 text-label font-medium">
                {label}
                <input name={`attr[${a.key}]`} defaultValue={params[`attr[${a.key}]`]} className={inputClass} />
              </label>
            );
          })}
          <label className="flex items-center gap-2 text-body">
            <input type="checkbox" name="verified" value="1" defaultChecked={params.verified === "1"} />
            {t("classifieds.verifiedOnly")}
          </label>
          <label className="flex items-center gap-2 text-body">
            <input type="checkbox" name="with_video" value="1" defaultChecked={params.with_video === "1"} />
            {t("classifieds.withVideo")}
          </label>
          {params.sort ? <input type="hidden" name="sort" value={params.sort} /> : null}
          <button type="submit" className={buttonClass("primary", "md", "w-full")}>
            {t("classifieds.applyFilters")}
          </button>
          <Link href={`${base}/search`} className="text-center text-body-sm text-link">
            {t("classifieds.clearFilters")}
          </Link>
        </form>

        <div className="flex min-w-0 flex-col gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {ads.length === 0 && !error ? (
            <EmptyState
              icon={<SearchX className="size-10" />}
              title={t("classifieds.noResults")}
              body={t("classifieds.noResultsHint")}
              action={
                <Link href={`${base}/post`} className={buttonClass()}>
                  {t("classifieds.postAd")}
                </Link>
              }
            />
          ) : (
            <AdGrid ads={ads} propertySlugs={propertySlugs} />
          )}
          {lastPage > 1 ? (
            <nav className="flex items-center justify-center gap-2" aria-label={t("classifieds.pagination")}>
              {page > 1 ? (
                <Link href={pageHref(page - 1)} className={buttonClass("outline", "sm")}>
                  {t("common.back")}
                </Link>
              ) : null}
              <span className="text-body-sm text-secondary">{t("classifieds.pageOf", { page, last: lastPage })}</span>
              {page < lastPage ? (
                <Link href={pageHref(page + 1)} className={buttonClass("outline", "sm")}>
                  {t("common.continue")}
                </Link>
              ) : null}
            </nav>
          ) : null}
        </div>
      </div>
    </div>
  );
}

const VERTICALS = ["general", "property", "motors", "jobs", "services"] as const;

function verticalLabel(t: ReturnType<typeof getT>, value: string): string {
  return (VERTICALS as readonly string[]).includes(value) ? t(`classifieds.verticals.${value as (typeof VERTICALS)[number]}`) : value.replace(/[_-]+/g, " ");
}
