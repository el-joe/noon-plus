"use client";

import { ChevronDown, MapPin } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import * as api from "@/lib/api/endpoints";
import { useCartMutation, useLocalStorage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { buttonClass } from "../ui/button";
import { Field, Input } from "../ui/field";
import { Modal } from "./modal";

export type DeliveryLocation = { city: string; area: string };

export function useDeliveryLocation() {
  const market = useMarket();
  return useLocalStorage<DeliveryLocation | null>(`nawy.location.${market.country.code}`, null);
}

/**
 * "Deliver to" (Figma 17:59): the city/area sets the cart's shipping
 * destination (PUT cart/shipping) so delivery dates and fees are quoted for it.
 */
export function DeliverTo() {
  const t = useTranslations();
  const market = useMarket();
  const [location, setLocation] = useDeliveryLocation();
  const [open, setOpen] = useState(false);
  const shipping = useCartMutation((m, body: { city: string; area: string }) => api.setCartShipping(m, body));

  const save = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const next = { city: String(form.get("city") ?? "").trim(), area: String(form.get("area") ?? "").trim() };
    if (!next.city) return;
    setLocation(next);
    shipping.mutate(next);
    setOpen(false);
  };

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="flex items-center gap-1.5 text-start">
        <MapPin className="size-5 shrink-0" />
        <span className="flex flex-col">
          <span className="text-caption font-medium">{t("header.deliverTo")}</span>
          <span className="max-w-36 truncate text-body font-medium">{location ? `${location.area ? `${location.area}, ` : ""}${location.city}` : market.country.name[market.locale]}</span>
        </span>
        <ChevronDown className="size-3.5" />
      </button>
      {open ? (
        <Modal title={t("location.title")} onClose={() => setOpen(false)}>
          <form onSubmit={save} className="flex flex-col gap-4">
            <p className="text-body text-secondary">{t("location.hint")}</p>
            <Field label={t("location.city")}>
              <Input name="city" required maxLength={120} defaultValue={location?.city ?? ""} autoComplete="address-level2" />
            </Field>
            <Field label={t("location.area")}>
              <Input name="area" maxLength={120} defaultValue={location?.area ?? ""} autoComplete="address-level3" />
            </Field>
            <button type="submit" className={buttonClass("primary", "lg")}>
              {t("location.save")}
            </button>
          </form>
        </Modal>
      ) : null}
    </>
  );
}
