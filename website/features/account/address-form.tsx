"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Modal } from "@/components/layout/modal";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import { Alert } from "@/components/ui/misc";
import { fieldErrors } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import type { Address } from "@/lib/api/schemas";
import { errorMessage, queryKeys } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

type AddressParts = { building?: string | null; apartment?: string | null; street?: string | null; area?: string | null; city?: string | null; landmark?: string | null };

export function formatAddress(a: AddressParts | null | undefined): string {
  if (!a) return "";
  return [a.apartment, a.building, a.street, a.area, a.city].filter(Boolean).join(", ");
}

const OTHER = "__other__";

/**
 * Add / edit address modal (Figma 102:17008) on the server address book
 * (G-W5). City / area come from `delivery-areas` (active shipping zones);
 * the phone is validated per country by the API and 422 field errors are
 * shown inline.
 */
export function AddressFormModal({ address, onClose, onSaved }: { address?: Address | null; onClose: () => void; onSaved?: (address: Address) => void }) {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const areas = useQuery({ queryKey: ["delivery-areas", market.slug], staleTime: 10 * 60_000, queryFn: async () => (await api.getDeliveryAreas(market.slug)).data });
  const cities = areas.data ?? [];
  const knownCity = !address?.city || cities.some((c) => c.city === address.city);
  const [city, setCity] = useState(address?.city ?? "");
  const [cityMode, setCityMode] = useState<"list" | "free">(knownCity ? "list" : "free");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const cityAreas = cities.find((c) => c.city === city)?.areas ?? [];

  const save = useMutation({
    mutationFn: async (body: api.AddressBookInput) => (address ? await api.updateAddress(market.slug, address.id, body) : await api.createAddress(market.slug, body)).data,
    onSuccess: (saved) => {
      client.invalidateQueries({ queryKey: queryKeys.addresses(market.slug) });
      onSaved?.(saved);
      onClose();
    },
    onError: (e) => setErrors(fieldErrors(e)),
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const f = new FormData(event.currentTarget);
    const v = (k: string) => String(f.get(k) ?? "").trim();
    setErrors({});
    save.mutate({
      label: v("label") || null,
      name: v("name"),
      phone: v("phone"),
      city: cityMode === "list" ? city : v("city_free"),
      area: v("area"),
      street: v("street"),
      building: v("building") || null,
      apartment: v("apartment") || null,
      landmark: v("landmark") || null,
      is_default: f.get("is_default") === "on" ? true : undefined,
    });
  };

  const useList = cityMode === "list" && cities.length > 0;

  return (
    <Modal title={address ? t("addressBook.editTitle") : t("addressBook.addTitle")} onClose={onClose}>
      <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2" noValidate>
        <fieldset className="flex gap-2 sm:col-span-2">
          <legend className="mb-1.5 text-label font-medium">{t("addressBook.label")}</legend>
          {(["home", "work", "other"] as const).map((label) => (
            <label key={label} className="cursor-pointer">
              <input type="radio" name="label" value={label} defaultChecked={(address?.label ?? "home") === label} className="peer sr-only" />
              <span className="inline-block rounded-full border border-line px-3 py-1 text-body-sm peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white">{t(`addressBook.labels.${label}`)}</span>
            </label>
          ))}
        </fieldset>
        <Field label={t("checkout.name")} error={errors.name}>
          <Input name="name" required maxLength={120} defaultValue={address?.name ?? ""} autoComplete="name" />
        </Field>
        <Field label={t("checkout.phone")} error={errors.phone} hint={t("addressBook.phoneHint")}>
          <Input name="phone" type="tel" required maxLength={32} dir="ltr" defaultValue={address?.phone ?? ""} autoComplete="tel" />
        </Field>
        {useList ? (
          <Field label={t("checkout.city")} error={errors.city}>
            <Select
              value={city}
              required
              onChange={(e) => {
                if (e.target.value === OTHER) {
                  setCityMode("free");
                  setCity("");
                } else setCity(e.target.value);
              }}
            >
              <option value="">{t("addressBook.chooseCity")}</option>
              {cities.map((c) => (
                <option key={c.city} value={c.city}>
                  {c.city}
                </option>
              ))}
              <option value={OTHER}>{t("addressBook.otherCity")}</option>
            </Select>
          </Field>
        ) : (
          <Field label={t("checkout.city")} error={errors.city}>
            <Input name="city_free" required maxLength={120} defaultValue={address?.city ?? ""} />
          </Field>
        )}
        {useList && cityAreas.length > 0 ? (
          <Field label={t("checkout.area")} error={errors.area}>
            <Select name="area" required defaultValue={address?.area ?? ""}>
              <option value="">{t("addressBook.chooseArea")}</option>
              {cityAreas.map((a) => (
                <option key={a.name} value={a.name}>
                  {a.name}
                </option>
              ))}
            </Select>
          </Field>
        ) : (
          <Field label={t("checkout.area")} error={errors.area}>
            <Input name="area" required maxLength={120} defaultValue={address?.area ?? ""} />
          </Field>
        )}
        <Field label={t("checkout.street")} error={errors.street} className="sm:col-span-2">
          <Input name="street" required maxLength={255} defaultValue={address?.street ?? ""} autoComplete="street-address" />
        </Field>
        <Field label={`${t("checkout.building")} ${t("common.optionalSuffix")}`} error={errors.building}>
          <Input name="building" maxLength={120} defaultValue={address?.building ?? ""} />
        </Field>
        <Field label={`${t("addressBook.apartment")} ${t("common.optionalSuffix")}`} error={errors.apartment}>
          <Input name="apartment" maxLength={60} defaultValue={address?.apartment ?? ""} />
        </Field>
        <Field label={`${t("addressBook.landmark")} ${t("common.optionalSuffix")}`} error={errors.landmark} className="sm:col-span-2">
          <Input name="landmark" maxLength={255} defaultValue={address?.landmark ?? ""} />
        </Field>
        {!address?.is_default ? (
          <label className="flex items-center gap-2 text-body-sm sm:col-span-2">
            <input type="checkbox" name="is_default" className="size-4 accent-ink" />
            {t("addressBook.setDefault")}
          </label>
        ) : null}
        {save.isError && Object.keys(errors).length === 0 ? (
          <Alert tone="danger" className="sm:col-span-2">
            {errorMessage(save.error, t("common.error"))}
          </Alert>
        ) : null}
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="ghost" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? t("common.saving") : t("common.save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
