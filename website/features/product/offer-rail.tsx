"use client";

import { ChevronDown, MapPin, RotateCcw, ShieldCheck, Wallet } from "lucide-react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Badge } from "@/components/ui/misc";
import { formatMoney } from "@/lib/format";
import { useHref } from "@/lib/market-context";
import { DeliveryPromise } from "./delivery-promise";
import { FulfilledByNawyBadge, isFulfilledByNawy } from "./fbn-badge";
import { useSelectedOffer } from "./offer-context";

/**
 * PDP right rail (Figma 24:2, QC-057): delivery card, seller card with the
 * other sellers' offers, then returns / warranty / secure payments.
 */
export function OfferRail({ madeToOrderDays, hasWarranty }: { madeToOrderDays: number | null; hasWarranty: boolean }) {
  const t = useTranslations();
  const href = useHref();
  const selected = useSelectedOffer();
  const [open, setOpen] = useState(false);
  const offer = selected?.offer ?? null;
  const others = selected?.others ?? [];
  const cheapest = others.reduce<(typeof others)[number] | null>((min, o) => (o.price && (!min?.price || Number(o.price.amount) < Number(min.price.amount)) ? o : min), null);

  return (
    <div className="flex flex-col gap-3">
      {offer ? (
        <section className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-4" aria-label={t("discovery.pdp.delivery")}>
          <p className="flex items-center gap-1.5 text-body-sm font-medium">
            <MapPin className="size-4" />
            {t("discovery.pdp.delivery")}
          </p>
          <DeliveryPromise delivery={offer.delivery} express={isFulfilledByNawy(offer) || offer.fulfilment === "express"} />
          {isFulfilledByNawy(offer) ? (
            <div className="flex flex-col gap-1">
              <FulfilledByNawyBadge offer={offer} />
              <p className="text-caption text-secondary">{t("discovery.pdp.fbnHint")}</p>
            </div>
          ) : null}
          {madeToOrderDays ? <p className="text-body-sm text-secondary">{t("product.madeToOrder", { days: madeToOrderDays })}</p> : null}
        </section>
      ) : null}

      {offer ? (
        <section className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4" aria-label={t("product.soldBy")}>
          <div className="flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-body-sm font-semibold text-brand-text">
              {(offer.seller.name ?? "?").split(/\s+/).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("")}
            </span>
            <div className="min-w-0">
              <p className="text-caption text-secondary">{t("product.soldBy")}</p>
              {offer.seller.slug ? (
                <Link href={href(`/seller/${encodeURIComponent(offer.seller.slug)}`)} className="block truncate text-body font-medium text-link hover:underline">
                  {offer.seller.name}
                </Link>
              ) : (
                <p className="truncate text-body font-medium">{offer.seller.name}</p>
              )}
            </div>
            {offer.condition && offer.condition !== "new" ? <Badge className="ms-auto">{offer.condition}</Badge> : null}
          </div>
          {offer.seller.rating ? (
            <div className="grid grid-cols-2 gap-2 text-center">
              <div className="rounded-md bg-subtle px-2 py-1.5">
                <p className="text-body font-semibold">★ {offer.seller.rating}</p>
                <p className="text-caption text-secondary">{t("discovery.pdp.sellerRating")}</p>
              </div>
              <div className="rounded-md bg-subtle px-2 py-1.5">
                <p className="text-body font-semibold">{offer.seller.rating_count ?? 0}</p>
                <p className="text-caption text-secondary">{t("discovery.pdp.ratings")}</p>
              </div>
            </div>
          ) : null}
          {others.length > 0 ? (
            <div>
              <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full items-center justify-between gap-2 text-body-sm font-medium text-link">
                {cheapest?.price ? t("discovery.pdp.otherOffersFrom", { count: others.length, price: formatMoney(cheapest.price) }) : t("product.otherOffers", { count: others.length })}
                <ChevronDown className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
              </button>
              {open ? (
                <ul className="mt-2 flex flex-col divide-y divide-line">
                  {others.map((o) => (
                    <li key={o.listing_id} className="flex items-center justify-between gap-3 py-2">
                      <div className="text-body-sm">
                        <p className="font-semibold">{formatMoney(o.price)}</p>
                        <p className="text-secondary">
                          {o.seller.name}
                          {o.condition && o.condition !== "new" ? ` · ${o.condition}` : ""}
                        </p>
                        <FulfilledByNawyBadge offer={o} />
                      </div>
                      <button type="button" onClick={() => selected?.choose(o.listing_id)} className="rounded-md border border-line px-3 py-1.5 text-body-sm font-medium hover:bg-subtle">
                        {t("product.choose")}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          ) : null}
        </section>
      ) : null}

      <section className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4">
        <p className="flex items-start gap-2 text-body-sm">
          <RotateCcw className="mt-0.5 size-4 shrink-0" />
          <span>
            <span className="block font-medium">{t("product.freeReturns")}</span>
            <span className="text-secondary">{t("product.freeReturnsHint")}</span>
          </span>
        </p>
        {hasWarranty ? (
          <p className="flex items-start gap-2 text-body-sm">
            <ShieldCheck className="mt-0.5 size-4 shrink-0" />
            <span>
              <span className="block font-medium">{t("discovery.pdp.warranty")}</span>
              <span className="text-secondary">{t("discovery.pdp.warrantyHint")}</span>
            </span>
          </p>
        ) : null}
        <p className="flex items-start gap-2 text-body-sm">
          <Wallet className="mt-0.5 size-4 shrink-0" />
          <span>
            <span className="block font-medium">{t("product.securePayments")}</span>
            <span className="text-secondary">{t("product.securePaymentsHint")}</span>
          </span>
        </p>
      </section>
    </div>
  );
}
