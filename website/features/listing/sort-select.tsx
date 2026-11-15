"use client";

import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";

const SORTS = ["recommended", "newest", "rating", "price_asc", "price_desc"] as const;

export function SortSelect({ value, basePath, params }: { value: string; basePath: string; params: Record<string, string> }) {
  const t = useTranslations("listing");
  const router = useRouter();
  return (
    <label className="flex items-center gap-2 text-body">
      <span className="text-secondary">{t("sortBy")}</span>
      <select
        value={value}
        onChange={(e) => {
          const next = new URLSearchParams({ ...params, sort: e.target.value });
          next.delete("page");
          router.push(`${basePath}?${next.toString()}`);
        }}
        className="h-9 rounded-md border border-line-strong bg-surface px-2 text-body font-medium"
      >
        {SORTS.map((sort) => (
          <option key={sort} value={sort}>
            {sort === "rating" ? t("sortRating") : t(`sort.${sort}`)}
          </option>
        ))}
      </select>
    </label>
  );
}
