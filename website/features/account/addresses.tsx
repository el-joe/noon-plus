"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { MapPin, Pencil, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Badge, Card, EmptyState, Skeleton } from "@/components/ui/misc";
import * as api from "@/lib/api/endpoints";
import type { Address } from "@/lib/api/schemas";
import { errorMessage, queryKeys, useAddresses } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { AddressFormModal, formatAddress } from "./address-form";

/** `/account/addresses` (Figma 52:5753) — the server address book (G-W5). */
export function AddressesView() {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const { data: addresses, isLoading, isError, error } = useAddresses();
  const [editing, setEditing] = useState<Address | "new" | null>(null);
  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.addresses(market.slug) });
  const remove = useMutation({ mutationFn: (id: number) => api.deleteAddress(market.slug, id), onSuccess: refresh });
  const makeDefault = useMutation({ mutationFn: (id: number) => api.makeDefaultAddress(market.slug, id), onSuccess: refresh });
  const list = addresses ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-h2 font-bold">{t("addressBook.title")}</h1>
        {list.length < 20 ? <Button onClick={() => setEditing("new")}>{t("addressBook.add")}</Button> : <span className="text-body-sm text-secondary">{t("addressBook.limit")}</span>}
      </div>
      {isError ? <Alert tone="danger">{errorMessage(error, t("common.error"))}</Alert> : null}
      {remove.isError || makeDefault.isError ? <Alert tone="danger">{errorMessage(remove.error ?? makeDefault.error, t("common.error"))}</Alert> : null}
      {isLoading ? <Skeleton className="h-40 w-full" /> : null}
      {!isLoading && list.length === 0 ? <EmptyState icon={<MapPin className="size-10" />} title={t("addressBook.empty")} body={t("addressBook.emptyHint")} /> : null}
      <div className="grid gap-3 md:grid-cols-2">
        {list.map((a) => (
          <Card key={a.id} className="flex flex-col gap-3">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-2">
                <span className="text-body font-semibold">{a.label ? t(`addressBook.labels.${a.label as "home"}`) : a.name}</span>
                {a.is_default ? <Badge tone="brand">{t("addressBook.default")}</Badge> : null}
              </div>
              <div className="flex items-center gap-3">
                <button type="button" onClick={() => setEditing(a)} className="flex items-center gap-1 text-body-sm text-link">
                  <Pencil className="size-3.5" /> {t("common.edit")}
                </button>
                <button
                  type="button"
                  disabled={remove.isPending}
                  onClick={() => window.confirm(t("addressBook.confirmDelete")) && remove.mutate(a.id)}
                  className="flex items-center gap-1 text-body-sm text-danger-text"
                >
                  <Trash2 className="size-3.5" /> {t("common.delete")}
                </button>
              </div>
            </div>
            <div className="text-body">
              <p className="font-medium">{a.name}</p>
              {a.phone ? (
                <p className="text-secondary" dir="ltr">
                  {a.phone}
                </p>
              ) : null}
              <p className="text-secondary">{formatAddress(a)}</p>
              {a.landmark ? <p className="text-body-sm text-muted">{a.landmark}</p> : null}
              {a.zone ? <p className="text-body-sm text-muted">{t("addressBook.zone", { zone: a.zone.name })}</p> : null}
            </div>
            {!a.is_default ? (
              <button type="button" onClick={() => makeDefault.mutate(a.id)} disabled={makeDefault.isPending} className="self-start text-body-sm font-medium text-link">
                {t("addressBook.makeDefault")}
              </button>
            ) : null}
          </Card>
        ))}
      </div>
      {editing ? <AddressFormModal address={editing === "new" ? null : editing} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}
