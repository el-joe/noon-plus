"use client";

import { useMutation } from "@tanstack/react-query";
import { Bell, Check } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Modal } from "@/components/layout/modal";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

const SORTS = ["recommended", "newest", "price_asc", "price_desc"] as const;

export function ClassifiedSortSelect({ value, basePath, params }: { value: string; basePath: string; params: Record<string, string> }) {
  const t = useTranslations("classifieds");
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
            {t(`sort.${sort}`)}
          </option>
        ))}
      </select>
    </label>
  );
}

/** Saved-search filters accepted by `POST classifieds/saved-searches` (BuyerController@saveSearch). */
export function savedSearchFilters(params: Record<string, string>): Record<string, unknown> {
  const filters: Record<string, unknown> = {};
  for (const key of ["q", "category", "kind", "city", "min_price", "max_price"] as const) if (params[key]) filters[key] = params[key];
  if (params.verified === "1") filters.verified = true;
  const attr: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(params)) {
    const match = /^attr\[([^\]]+)\](?:\[(min|max)\])?$/.exec(key);
    if (!match || !value) continue;
    if (match[2]) attr[match[1]] = { ...((attr[match[1]] as object) ?? {}), [match[2]]: value };
    else attr[match[1]] = value;
  }
  if (Object.keys(attr).length) filters.attr = attr;
  return filters;
}

/** "Save search" with alert frequency (Figma Saved searches 100:11530). */
export function SaveSearchButton({ params, defaultName }: { params: Record<string, string>; defaultName: string }) {
  const t = useTranslations("classifieds");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const { isSignedIn } = useSession();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(defaultName);
  const [frequency, setFrequency] = useState<"daily" | "instant">("daily");
  const save = useMutation({
    mutationFn: () => api.createSavedSearch(market.slug, { name: name.trim() || defaultName, filters: savedSearchFilters(params), alert_frequency: frequency }),
  });

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => (isSignedIn ? setOpen(true) : router.push(`${href("/login")}?next=${encodeURIComponent(window.location.pathname + window.location.search)}`))}
      >
        {save.isSuccess ? <Check className="size-4" /> : <Bell className="size-4" />}
        {save.isSuccess ? t("searchSaved") : t("saveSearch")}
      </Button>
      {open ? (
        <Modal title={t("saveSearch")} onClose={() => setOpen(false)}>
          {save.isSuccess ? (
            <Alert tone="success">{t("searchSavedHint")}</Alert>
          ) : (
            <form
              className="flex flex-col gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                save.mutate();
              }}
            >
              <Field label={t("searchName")}>
                <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} required />
              </Field>
              <Field label={t("alertFrequency")}>
                <Select value={frequency} onChange={(e) => setFrequency(e.target.value as "daily" | "instant")}>
                  <option value="daily">{t("alerts.daily")}</option>
                  <option value="instant">{t("alerts.instant")}</option>
                </Select>
              </Field>
              {save.error ? <Alert tone="danger">{errorMessage(save.error, tc("error"))}</Alert> : null}
              <Button type="submit" disabled={save.isPending}>
                {t("saveSearch")}
              </Button>
            </form>
          )}
        </Modal>
      ) : null}
    </>
  );
}
