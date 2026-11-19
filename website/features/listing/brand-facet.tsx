"use client";

import { Search } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

export type FacetRow = { value: string; label: string; count: number };

/**
 * Brand rail group (design): search box, the first brands, "See all".
 * Hidden rows stay in the form so a checked brand is kept when filtering
 * the list. The search box has no name (never sent).
 */
export function BrandFacet({ rows, selected, visible = 6 }: { rows: FacetRow[]; selected: string[]; visible?: number }) {
  const t = useTranslations("categoryPage");
  const [term, setTerm] = useState("");
  const [all, setAll] = useState(false);
  const needle = term.trim().toLocaleLowerCase();
  const matches = rows.filter((row) => !needle || row.label.toLocaleLowerCase().includes(needle));
  const shown = new Set(needle || all ? matches.map((r) => r.value) : [...selected, ...rows.slice(0, visible).map((r) => r.value)]);

  return (
    <div className="flex flex-col gap-2">
      {rows.length > visible ? (
        <label className="relative block">
          <Search className="pointer-events-none absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted" />
          <input type="search" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t("brand")} aria-label={t("brand")} className="h-8 w-full rounded-md border border-line-strong bg-surface ps-7 pe-2 text-body-sm" />
        </label>
      ) : null}
      <ul className="flex max-h-72 flex-col gap-1.5 overflow-y-auto pe-1">
        {rows.map((row) => (
          <li key={row.value} hidden={!shown.has(row.value)}>
            <label className="flex items-center gap-2 text-body-sm">
              <input type="checkbox" name="brand" value={row.value} defaultChecked={selected.includes(row.value)} className="size-4 accent-brand" />
              <span className="flex-1 truncate">{row.label}</span>
              <span className="text-caption text-muted tabular-nums">{row.count}</span>
            </label>
          </li>
        ))}
      </ul>
      {needle && matches.length === 0 ? <p className="text-caption text-muted">{t("noBrand")}</p> : null}
      {!needle && rows.length > visible ? (
        <button type="button" onClick={() => setAll((v) => !v)} className="self-start text-body-sm font-medium text-link">
          {all ? t("seeLess") : t("seeAll")}
        </button>
      ) : null}
    </div>
  );
}
