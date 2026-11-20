"use client";

import clsx from "clsx";
import { Minus, Plus, ShieldCheck, ShoppingCart } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Alert, Badge } from "@/components/ui/misc";
import { useRecordView } from "@/features/search/recently-viewed";
import * as api from "@/lib/api/endpoints";
import type { Offer, ProductDetail } from "@/lib/api/schemas";
import { discountPercent, formatAmount, formatMoney, savedAmount } from "@/lib/format";
import { errorMessage, useCartMutation, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { Countdown } from "@/features/page-builder/countdown";
import { DeliveryPromise } from "./delivery-promise";
import { FulfilledByNawyBadge, isFulfilledByNawy } from "./fbn-badge";
import { BnplLine } from "./bnpl-line";
import { CouponChips } from "./coupon-chips";
import { useSelectedOffer } from "./offer-context";
import { MeasurementsForm, measurementPayload, validateMeasurements, type MeasurementValues } from "./measurements";
import { SizeGuideButton } from "./size-guide";

type Options = Record<string, string>;

function optionsOf(offer: Offer): Options {
  const raw = offer.options;
  if (!raw || Array.isArray(raw)) return {};
  return Object.fromEntries(Object.entries(raw).filter(([, v]) => typeof v === "string" || typeof v === "number").map(([k, v]) => [k, String(v)]));
}

/**
 * PDP buy box (Figma 24:2): variants, price + VAT note, flash deal, delivery
 * ETA, warranty plans, quantity, Add to cart / Buy now, and the other sellers'
 * offers for the chosen variant (multi-seller buy box, BuyBox::offersFor).
 */
export function BuyBox({ product }: { product: ProductDetail }) {
  const t = useTranslations();
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const session = useSession();
  const offers = useMemo(() => (product.buy_box ? [product.buy_box, ...product.other_offers] : []), [product]);

  const axes = useMemo(() => {
    const map = new Map<string, string[]>();
    for (const offer of offers) {
      for (const [key, value] of Object.entries(optionsOf(offer))) {
        const values = map.get(key) ?? [];
        if (!values.includes(value)) values.push(value);
        map.set(key, values);
      }
    }
    return [...map.entries()];
  }, [offers]);

  const [selected, setSelected] = useState<Options>(() => (product.buy_box ? optionsOf(product.buy_box) : {}));
  const matching = offers.filter((o) => Object.entries(selected).every(([k, v]) => optionsOf(o)[k] === v));
  const [listingId, setListingId] = useState<number | null>(product.buy_box?.listing_id ?? null);
  const offer = matching.find((o) => o.listing_id === listingId) ?? matching[0] ?? null;
  const others = matching.filter((o) => o !== offer);
  const [quantity, setQuantity] = useState(1);
  const [plan, setPlan] = useState<number | null>(null);
  const [added, setAdded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const tm = useTranslations("measurements");
  const needsMeasurements = product.made_to_order && product.measurement_fields.length > 0;
  const [measurements, setMeasurements] = useState<MeasurementValues>({});
  const [measurementNote, setMeasurementNote] = useState("");
  const [measurementErrors, setMeasurementErrors] = useState<Record<string, string>>({});
  const add = useCartMutation(async (m, vars: { listingId: number; quantity: number; plan: number | null; measurements: Record<string, string | number> | null; note: string }) => {
    let result = await api.setCartItem(m, vars.listingId, vars.quantity);
    if (vars.plan) result = await api.setCartWarranty(m, vars.listingId, vars.plan);
    if (vars.measurements) result = await api.setCartMeasurements(m, vars.listingId, vars.measurements, vars.note);
    return result;
  });

  // Remember this product for "Recently viewed" (device, and the account when signed in).
  useRecordView({
    slug: product.slug,
    title: product.title,
    brand: product.brand,
    price: product.buy_box?.price ?? product.from_price,
    listingId: product.buy_box?.listing_id ?? null,
    image: product.images[0]?.url ?? null,
    rating: product.rating ?? null,
    badges: product.badges ?? [],
  });

  // Share the chosen offer with the right rail (delivery + seller cards).
  const rail = useSelectedOffer();
  const setRailOffer = rail?.setOffer;
  const setRailOthers = rail?.setOthers;
  const setRailChoose = rail?.setChoose;
  const othersKey = others.map((o) => o.listing_id).join(",");
  useEffect(() => {
    setRailOffer?.(offer);
    setRailOthers?.(others);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offer?.listing_id, othersKey, setRailOffer, setRailOthers]);
  useEffect(() => {
    setRailChoose?.((id: number) => setListingId(id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!offer) {
    return <Alert tone="warning">{t("product.unavailable")}</Alert>;
  }

  const percent = discountPercent(offer.price, offer.was_price);
  const max = Math.max(1, Math.min(50, offer.in_stock, offer.flash_sale?.max_per_customer ?? 50));
  const submit = (then: "stay" | "checkout") => {
    setError(null);
    if (needsMeasurements) {
      const errors = validateMeasurements(product.measurement_fields, measurements, (key, values) => tm(key, values));
      setMeasurementErrors(errors);
      if (Object.keys(errors).length > 0) {
        document.getElementById("measurements")?.scrollIntoView({ behavior: "smooth", block: "center" });
        return;
      }
    }
    add.mutate(
      { listingId: offer.listing_id, quantity, plan, measurements: needsMeasurements ? measurementPayload(measurements) : null, note: measurementNote },
      {
        onSuccess: () => {
          setAdded(true);
          if (then !== "checkout") return;
          // Buy now as a guest: the item is in the guest cart (merged on sign-in); sign in first, then land on checkout.
          const checkout = href("/checkout");
          router.push(!session.isLoading && !session.isSignedIn ? `${href("/login")}?next=${encodeURIComponent(checkout)}` : checkout);
        },
        onError: (e) => setError(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div>
        <p className="flex items-baseline gap-1.5">
          <span className="text-body font-medium">{offer.price?.currency}</span>
          <span className="text-h1 font-bold">{offer.price ? formatAmount(offer.price.amount) : "—"}</span>
          <span className="text-body-sm text-secondary">{t("product.inclVat")}</span>
        </p>
        {offer.was_price && percent ? (
          <p className="flex items-center gap-2 text-body-sm">
            <span className="text-muted line-through">
              {t("product.was")} {formatMoney(offer.was_price)}
            </span>
            <span className="font-medium text-success-text">
              {(() => {
                const saved = savedAmount(offer.price, offer.was_price);
                return saved ? `${t("product.save", { amount: formatMoney(saved) })} (${percent}%)` : t("product.off", { percent });
              })()}
            </span>
          </p>
        ) : null}
        {offer.flash_sale ? (
          <div className="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-warning-subtle px-3 py-2 text-body-sm text-danger-text">
            <span className="font-semibold">{offer.flash_sale.event}</span>
            <Countdown endsAt={offer.flash_sale.ends_at} tone="danger" />
            {offer.flash_sale.remaining != null ? <span>{t("product.flashRemaining", { count: offer.flash_sale.remaining })}</span> : null}
          </div>
        ) : null}
      </div>

      <BnplLine price={offer.price} />
      <CouponChips slug={product.slug} />

      {product.has_size_guide && axes.length === 0 ? <SizeGuideButton slug={product.slug} /> : null}

      {axes.map(([axis, values], axisIndex) => (
        <fieldset key={axis}>
          <legend className="mb-2 flex w-full items-center justify-between gap-3 text-body">
            <span>
              <span className="capitalize text-secondary">{axis}:</span> <span className="font-medium">{selected[axis]}</span>
            </span>
            {product.has_size_guide && axisIndex === axes.length - 1 ? <SizeGuideButton slug={product.slug} /> : null}
          </legend>
          <div className="flex flex-wrap gap-2">
            {values.map((value) => {
              const available = offers.some((o) => optionsOf(o)[axis] === value && o.in_stock > 0);
              return (
                <button
                  key={value}
                  type="button"
                  onClick={() => {
                    setSelected({ ...selected, [axis]: value });
                    setListingId(null);
                  }}
                  aria-pressed={selected[axis] === value}
                  className={clsx(
                    "rounded-md border px-3 py-2 text-body-sm",
                    selected[axis] === value ? "border-brand bg-brand-subtle font-semibold" : "border-line bg-surface",
                    !available && "text-muted line-through",
                  )}
                >
                  {value}
                </button>
              );
            })}
          </div>
        </fieldset>
      ))}

      {rail ? null : (
      <div className="rounded-lg border border-line bg-surface p-3">
        <DeliveryPromise delivery={offer.delivery} express={isFulfilledByNawy(offer) || offer.fulfilment === "express"} />
        {product.made_to_order && product.lead_days ? <p className="mt-1 text-body-sm text-secondary">{t("product.madeToOrder", { days: product.lead_days })}</p> : null}
        <p className="mt-2 text-body-sm text-secondary">
          {t("product.soldBy")} <span className="font-medium text-link">{offer.seller.name}</span>
          {offer.seller.rating ? (
            <span>
              {" "}
              · ★ {offer.seller.rating}
              {offer.seller.rating_count ? ` (${t("product.sellerRatingCount", { count: offer.seller.rating_count })})` : ""}
            </span>
          ) : null}
          {offer.condition && offer.condition !== "new" ? <Badge className="ms-2">{offer.condition}</Badge> : null}
        </p>
        <div className="mt-2"><FulfilledByNawyBadge offer={offer} withHint /></div>
      </div>
      )}

      {product.warranty_plans.length > 0 ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 flex items-center gap-1.5 text-body font-semibold">
            <ShieldCheck className="size-4" />
            {t("product.warrantyTitle")}
          </legend>
          <label className="flex items-center gap-2 text-body-sm">
            <input type="radio" name="warranty" checked={plan === null} onChange={() => setPlan(null)} />
            {t("product.warrantyNone")}
          </label>
          {product.warranty_plans.map((p) => (
            <label key={p.plan_id} className="flex items-center gap-2 text-body-sm">
              <input type="radio" name="warranty" checked={plan === p.plan_id} onChange={() => setPlan(p.plan_id)} />
              <span className="flex-1">
                {p.name} · {t("product.warrantyMonths", { months: p.months })}
              </span>
              <span className="font-medium">{formatMoney(p.price)}</span>
            </label>
          ))}
        </fieldset>
      ) : null}

      {needsMeasurements ? (
        <div id="measurements" className="scroll-mt-28">
          <MeasurementsForm
            fields={product.measurement_fields}
            seller={offer.seller.name}
            values={measurements}
            note={measurementNote}
            errors={measurementErrors}
            onChange={setMeasurements}
            onNoteChange={setMeasurementNote}
          />
        </div>
      ) : null}

      {offer.in_stock > 0 ? (
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex h-12 items-center rounded-md border border-line-strong bg-surface" role="group" aria-label={t("product.quantity")}>
            <button type="button" className="px-3" onClick={() => setQuantity(Math.max(1, quantity - 1))} aria-label={t("cart.decrease")}>
              <Minus className="size-4" />
            </button>
            <span className="w-8 text-center text-body font-medium tabular-nums">{quantity}</span>
            <button type="button" className="px-3" onClick={() => setQuantity(Math.min(max, quantity + 1))} aria-label={t("cart.increase")}>
              <Plus className="size-4" />
            </button>
          </div>
          {/* Mobile (Figma 85:445): Add to cart and Buy now share the row, full width. */}
          <div className="grid basis-full grid-cols-2 gap-3 sm:flex sm:basis-auto">
            <Button size="lg" className="whitespace-nowrap max-sm:px-3" onClick={() => submit("stay")} disabled={add.isPending}>
              <ShoppingCart className="size-5" />
              {add.isPending ? t("product.adding") : t("product.addToCart")}
            </Button>
            <Button size="lg" variant="dark" className="whitespace-nowrap max-sm:px-3" onClick={() => submit("checkout")} disabled={add.isPending}>
              {t("product.buyNow")}
            </Button>
          </div>
          {offer.in_stock <= 5 ? <span className="text-body-sm text-danger-text">{t("product.lowStock", { count: offer.in_stock })}</span> : null}
        </div>
      ) : (
        <Alert tone="warning">{t("product.outOfStock")}</Alert>
      )}

      {added && !add.isPending ? (
        <Alert tone="success">
          {t("product.added")} ·{" "}
          <Link href={href("/cart")} className="font-semibold underline">
            {t("product.viewCart")}
          </Link>
        </Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}

      {!rail && others.length > 0 ? (
        <section className="rounded-lg border border-line bg-surface p-3">
          <h2 className="mb-2 text-body font-semibold">
            {t("product.sellerOffers")} · {t("product.otherOffers", { count: others.length })}
          </h2>
          <ul className="flex flex-col divide-y divide-line">
            {others.map((o) => (
              <li key={o.listing_id} className="flex items-center justify-between gap-3 py-2">
                <div className="text-body-sm">
                  <p className="font-semibold">{formatMoney(o.price)}</p>
                  <p className="text-secondary">
                    {o.seller.name}
                    {o.condition && o.condition !== "new" ? ` · ${o.condition}` : ""}
                  </p>
                </div>
                <button type="button" onClick={() => setListingId(o.listing_id)} className="rounded-md border border-line px-3 py-1.5 text-body-sm font-medium hover:bg-subtle">
                  {t("product.choose")}
                </button>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
