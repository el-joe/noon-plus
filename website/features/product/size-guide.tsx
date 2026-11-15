"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { Ruler, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/field";
import { Alert, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import type { SizeGuide } from "@/lib/api/schemas";
import { humanize } from "@/lib/format";
import { useMarket } from "@/lib/market-context";

type Column = { key: string; label: string; isLabel?: boolean };

/** Columns from `column_defs` (label + unit, round 2), else the plain `columns` keys, else the first row. */
function columnKeys(columns: unknown[], rows: Record<string, unknown>[], defs: SizeGuide["column_defs"] = []): Column[] {
  if (defs.length) return defs.map((d) => ({ key: d.key, label: `${d.label || humanize(d.key)}${d.unit ? ` (${d.unit})` : ""}`, isLabel: d.is_label }));
  const fromColumns = columns
    .map((c) => (typeof c === "string" ? { key: c, label: humanize(c) } : c && typeof c === "object" && "key" in c ? { key: String((c as { key: unknown }).key), label: String((c as { label?: unknown }).label ?? humanize(String((c as { key: unknown }).key))) } : null))
    .filter((c): c is { key: string; label: string } => !!c);
  if (fromColumns.length) return fromColumns;
  return Object.keys(rows[0] ?? {}).map((k) => ({ key: k, label: humanize(k) }));
}

/** "Size guide" link + right drawer (Figma 65:5965): the table in cm / inches and, for abayas, "Find my size" by height. */
export function SizeGuideButton({ slug }: { slug: string }) {
  const t = useTranslations("sizeGuide");
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center gap-1 text-body-sm font-medium text-link hover:underline">
        <Ruler className="size-4" />
        {t("open")}
      </button>
      {open ? <SizeGuideDrawer slug={slug} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function SizeGuideDrawer({ slug, onClose }: { slug: string; onClose: () => void }) {
  const t = useTranslations("sizeGuide");
  const tc = useTranslations("common");
  const market = useMarket();
  const [unit, setUnit] = useState<"cm" | "in" | null>(null);
  const [height, setHeight] = useState<{ value: string; unit: "cm" | "ft" } | null>(null);
  const [heightUnit, setHeightUnit] = useState<"cm" | "ft">("cm");

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      window.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [onClose]);

  const guide = useQuery({
    queryKey: ["size-guide", market.slug, slug, unit, height?.value, height?.unit],
    queryFn: async () => (await api.getSizeGuide(market.slug, slug, { unit: unit ?? undefined, height: height?.value, height_unit: height?.unit })).data,
    retry: false,
  });

  const data = guide.data;
  const isAbaya = data?.type === "abaya_height_length";
  const cols = data ? columnKeys(data.columns, data.rows, data.column_defs) : [];
  const rec = data?.recommendation;

  const find = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const value = String(new FormData(event.currentTarget).get("height") ?? "").trim();
    if (value) setHeight({ value, unit: heightUnit });
  };

  const highlighted = (row: Record<string, unknown>) => {
    if (!rec) return false;
    return Object.values(row).some((v) => Number(v) === rec.length_in);
  };

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-ink/50" onClick={onClose}>
      <aside role="dialog" aria-modal="true" aria-label={t("title")} onClick={(e) => e.stopPropagation()} className="flex h-full w-full max-w-[480px] flex-col overflow-y-auto bg-surface p-5 shadow-lg">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-h3 font-semibold">{t("title")}</h2>
          <button type="button" onClick={onClose} className="rounded-full p-1 hover:bg-subtle" aria-label={tc("close")}>
            <X className="size-5" />
          </button>
        </div>
        {guide.isLoading ? (
          <Skeleton className="h-80 w-full" />
        ) : !data ? (
          <Alert tone="info">{t("unavailable")}</Alert>
        ) : (
          <div className="flex flex-col gap-4">
            {data.name ? <p className="text-body font-medium">{data.name}</p> : null}
            {isAbaya ? (
              <form onSubmit={find} className="flex flex-col gap-2 rounded-lg border border-brand/40 bg-brand-subtle p-4">
                <p className="text-body font-semibold">{t("findMySize")}</p>
                <label className="text-body-sm" htmlFor="sg-height">
                  {t("height")}
                </label>
                <div className="flex gap-2">
                  <Input id="sg-height" name="height" inputMode="decimal" placeholder={t("heightPlaceholder")} className="flex-1" dir="ltr" />
                  <select value={heightUnit} onChange={(e) => setHeightUnit(e.target.value as "cm" | "ft")} className="h-11 rounded-md border border-line-strong bg-surface px-2 text-body" aria-label={t("units")}>
                    <option value="cm">{t("unitCm")}</option>
                    <option value="ft">{t("unitFt")}</option>
                  </select>
                  <Button type="submit">{t("show")}</Button>
                </div>
                {rec ? (
                  <p className="text-body-sm font-medium">
                    {rec.sleeve_in != null ? t("recommendedSleeve", { length: rec.length_in, sleeve: rec.sleeve_in }) : t("recommended", { length: rec.length_in })}
                  </p>
                ) : null}
                {guide.isError ? <p className="text-body-sm text-danger-text">{tc("error")}</p> : null}
              </form>
            ) : null}
            <div className="overflow-x-auto rounded-lg border border-line">
              <table className="w-full text-body-sm">
                <thead className="bg-subtle">
                  <tr>
                    {cols.map((c) => (
                      <th key={c.key} className="px-3 py-2 text-start font-medium">
                        {c.label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((row, i) => (
                    <tr key={i} className={clsx("border-t border-line", highlighted(row) && "bg-brand-subtle")}>
                      {cols.map((c) =>
                        c.isLabel ? (
                          <th key={c.key} scope="row" className="px-3 py-2 text-start font-semibold">
                            {String(row[c.key] ?? "")}
                          </th>
                        ) : (
                          <td key={c.key} className="px-3 py-2 tabular-nums">
                            {String(row[c.key] ?? "")}
                          </td>
                        ),
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-caption text-secondary">{data.notes || t("measurementsNote")}</p>
            {!isAbaya ? (
              <div className="flex items-center gap-2 text-body-sm">
                <span>{t("units")}:</span>
                {(["in", "cm"] as const).map((u) => (
                  <button key={u} type="button" onClick={() => setUnit(u)} aria-pressed={data.unit === u} className={clsx("rounded-full border px-3 py-1", data.unit === u ? "border-ink bg-ink text-white" : "border-line")}>
                    {u === "cm" ? t("unitCm") : t("unitIn")}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        )}
      </aside>
    </div>
  );
}
