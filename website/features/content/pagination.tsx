import clsx from "clsx";
import Link from "next/link";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

/** Prev / numbered / next pager driven by `?page=` (keeps the other query params). */
export function Pager({ market, basePath, query, page, lastPage }: { market: Market; basePath: string; query: Record<string, string | undefined>; page: number; lastPage: number }) {
  if (lastPage <= 1) return null;
  const t = getT(market.locale);
  const link = (target: number) => {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) if (v && k !== "page") params.set(k, v);
    if (target > 1) params.set("page", String(target));
    const qs = params.toString();
    return qs ? `${basePath}?${qs}` : basePath;
  };
  const pages = Array.from({ length: lastPage }, (_, i) => i + 1).filter((p) => p === 1 || p === lastPage || Math.abs(p - page) <= 2);
  const cell = "inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-line bg-surface px-3 text-body-sm";
  return (
    <nav className="flex flex-wrap items-center justify-center gap-2" aria-label={t("content.pagination.page", { page, total: lastPage })}>
      {page > 1 ? (
        <Link href={link(page - 1)} className={cell} rel="prev">
          ‹ {t("content.pagination.previous")}
        </Link>
      ) : null}
      {pages.map((p, i) => (
        <span key={p} className="contents">
          {i > 0 && p - pages[i - 1] > 1 ? <span className="text-muted">…</span> : null}
          <Link href={link(p)} aria-current={p === page ? "page" : undefined} className={clsx(cell, p === page && "border-brand bg-brand font-semibold")}>
            {p}
          </Link>
        </span>
      ))}
      {page < lastPage ? (
        <Link href={link(page + 1)} className={cell} rel="next">
          {t("content.pagination.next")} ›
        </Link>
      ) : null}
    </nav>
  );
}

/** First value of a search param. */
export function firstParam(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

/** Flatten Next search params into a simple record. */
export function flatParams(raw: Record<string, string | string[] | undefined>): Record<string, string | undefined> {
  return Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, firstParam(v)]));
}

/** Breadcrumb trail (Home › … › current). */
export function Crumbs({ market, items }: { market: Market; items: { label: string; href?: string }[] }) {
  const t = getT(market.locale);
  const all = [{ label: t("common.home"), href: `/${market.slug}` }, ...items];
  return (
    <nav aria-label={t("common.breadcrumb")} className="flex flex-wrap items-center gap-1.5 text-body-sm text-secondary">
      {all.map((item, i) => (
        <span key={i} className="flex items-center gap-1.5">
          {i > 0 ? <span aria-hidden className="rtl:rotate-180">›</span> : null}
          {item.href && i < all.length - 1 ? (
            <Link href={item.href} className="hover:text-ink">
              {item.label}
            </Link>
          ) : (
            <span className="line-clamp-1 text-ink">{item.label}</span>
          )}
        </span>
      ))}
    </nav>
  );
}
