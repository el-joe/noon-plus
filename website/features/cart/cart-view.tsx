"use client";

import { Heart, Minus, Plus, ShoppingCart, Store, Trash2, Truck } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, type ReactNode } from "react";
import { Button, ButtonLink } from "@/components/ui/button";
import { Alert, Card, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { DeliveryPromise } from "@/features/product/delivery-promise";
import * as api from "@/lib/api/endpoints";
import type { Cart, CartLine } from "@/lib/api/schemas";
import { formatAmount, formatMoney } from "@/lib/format";
import { errorMessage, fieldErrors, queryKeys, useCart, useCartMutation, useLoginRedirect, useMounted, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";
import { useQueryClient } from "@tanstack/react-query";
import { MeasurementsForm, measurementPayload, validateMeasurements, type MeasurementValues } from "@/features/product/measurements";
import { useHref } from "@/lib/market-context";
import Link from "next/link";
import { FreeDeliveryBar } from "./free-delivery-bar";
import { CartWarnings, CouponBox, Totals } from "./summary";

/** `/cart` (Figma Cart desktop 25:275, empty 68:1014). */
export function CartView() {
  const t = useTranslations();
  const href = useHref();
  const mounted = useMounted();
  const { data: cart, isLoading, isFetched } = useCart();

  if (!mounted || (isLoading && !isFetched)) {
    return (
      <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
        <Skeleton className="h-80" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  const shipments = cart?.shipments ?? [];
  const missing = shipments.flatMap((s) => s.items).filter((line) => line.measurements_missing);
  const lines = shipments.flatMap((s) => s.items);
  if (!cart || lines.length === 0) {
    return (
      <EmptyState
        icon={<ShoppingCart className="size-12" />}
        title={t("cart.emptyTitle")}
        body={t("cart.emptyHint")}
        action={<ButtonLink href={href("/")}>{t("cart.startShopping")}</ButtonLink>}
      />
    );
  }

  const items = lines.reduce((n, i) => n + i.quantity, 0);
  const freeShipping = shipments.some((s) => s.free_shipping_threshold_applied);

  return (
    <div className="flex flex-col gap-4">
      <h1 className="flex items-baseline gap-2 text-h1 font-bold">
        {t("cart.title")}
        <span className="text-body font-normal text-secondary">{t("cart.summary", { items: t("common.items", { count: items }), sellers: shipments.length })}</span>
      </h1>
      <div className="grid items-start gap-6 lg:grid-cols-[1fr_340px]">
        <div className="flex flex-col gap-4">
          {cart.free_delivery ? (
            <FreeDeliveryBar freeDelivery={cart.free_delivery} />
          ) : freeShipping ? (
            <Alert tone="success" className="flex items-center gap-2">
              <Truck className="size-4" /> {t("cart.freeShippingApplied")}
            </Alert>
          ) : null}
          <CartWarnings cart={cart} />
          {shipments.map((shipment, index) => (
            <Card key={`${shipment.seller}-${index}`} className="p-0 md:p-0">
              <div className="flex items-center justify-between border-b border-line px-4 py-3 text-body-sm">
                <span className="flex items-center gap-2">
                  <Store className="size-4 text-secondary" />
                  <span className="text-secondary">{t("cart.soldBy")}</span>
                  <span className="font-medium text-link">{shipment.seller}</span>
                </span>
                <span className="text-muted">{t("cart.shipmentOf", { n: index + 1, total: shipments.length })}</span>
              </div>
              <ul className="divide-y divide-line">
                {shipment.items.map((line) => (
                  <CartLineRow key={line.listing_id} line={line} delivery={<DeliveryPromise delivery={shipment.delivery} express={shipment.method === "express"} />} />
                ))}
              </ul>
            </Card>
          ))}
        </div>
        <aside className="flex flex-col gap-4 lg:sticky lg:top-32">
          <Card>
            <h2 className="mb-4 text-h3 font-semibold">{t("cart.orderSummary")}</h2>
            <CouponBox cart={cart} />
            <div className="my-4 border-t border-line" />
            <ShippingMethods cart={cart} />
            <Totals cart={cart} />
            {missing.length > 0 ? (
              <>
                <Alert tone="warning" className="mt-4">
                  {t("measurements.missingBeforeCheckout", { count: missing.length })}
                </Alert>
                <Button size="lg" className="mt-3 w-full" onClick={() => document.getElementById(`measurements-${missing[0].listing_id}`)?.scrollIntoView({ behavior: "smooth", block: "center" })}>
                  {t("measurements.edit")}
                </Button>
              </>
            ) : (
              <ButtonLink href={href("/checkout")} size="lg" className="mt-4 w-full">
                {t("cart.checkout")}
              </ButtonLink>
            )}
            <p className="mt-3 text-body-sm text-secondary">{t("cart.payHint")}</p>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function CartLineRow({ line, delivery }: { line: CartLine; delivery: ReactNode }) {
  const t = useTranslations();
  const setQty = useCartMutation((m, v: { id: number; qty: number }) => api.setCartItem(m, v.id, v.qty));
  const remove = useCartMutation((m, id: number) => api.removeCartItem(m, id));
  const warranty = useCartMutation((m, v: { id: number; plan: number | null }) => api.setCartWarranty(m, v.id, v.plan));
  const busy = setQty.isPending || remove.isPending;
  const href = useHref();
  const discounted = Number(line.regular_price.amount) > Number(line.unit_price.amount);
  const productHref = line.product_slug ? href(`/products/${line.product_slug}`) : null;
  const measurements = line.measurements && !Array.isArray(line.measurements) ? Object.entries(line.measurements) : [];
  const fields = line.measurement_fields;
  const madeToOrder = line.fulfilment_mode === "made_to_order" || fields.length > 0 || line.measurements_required;
  const labelOf = (key: string) => {
    const f = fields.find((x) => x.key === key);
    return f ? `${f.label || key.replace(/_/g, " ")}${f.unit ? ` (${f.unit})` : ""}` : key.replace(/_/g, " ");
  };

  return (
    <li className="flex gap-4 p-4">
      <div className="size-24 shrink-0 overflow-hidden rounded-md border border-line">
        {productHref ? (
          <Link href={productHref}>
            <ProductImage src={line.image?.url} alt={line.image?.alt || line.title} />
          </Link>
        ) : (
          <ProductImage src={line.image?.url} alt={line.image?.alt || line.title} />
        )}
      </div>
      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          {productHref ? (
            <Link href={productHref} className="text-body font-medium hover:underline">
              {line.title}
            </Link>
          ) : (
            <p className="text-body font-medium">{line.title}</p>
          )}
          <div className="text-end">
            <p className="flex items-baseline justify-end gap-1">
              <span className="text-caption">{line.unit_price.currency}</span>
              <span className="text-body-lg font-bold">{formatAmount(line.unit_price.amount)}</span>
            </p>
            {discounted ? <p className="text-body-sm text-muted line-through">{formatAmount(line.regular_price.amount)}</p> : null}
          </div>
        </div>
        {line.sku ? <p className="text-body-sm text-secondary">{line.sku}</p> : null}
        {delivery}
        {madeToOrder && fields.length > 0 ? (
          <CartMeasurements line={line} labelOf={labelOf} />
        ) : madeToOrder && measurements.length > 0 ? (
          <div className="rounded-md bg-subtle px-3 py-2 text-body-sm">
            <p className="font-medium">{t("measurements.inCart")}</p>
            <p className="text-secondary">
              {measurements.map(([k, v]) => `${labelOf(k)}: ${String(v)}`).join(" · ")}
              {line.measurement_note ? ` — ${line.measurement_note}` : ""}
            </p>
          </div>
        ) : madeToOrder ? (
          <p className="text-body-sm text-warning-text">
            {t("measurements.missing")}
            {productHref ? (
              <>
                {" · "}
                <Link href={productHref} className="font-medium underline">
                  {t("measurements.edit")}
                </Link>
              </>
            ) : null}
          </p>
        ) : null}
        {line.warranty_options.length > 0 ? (
          <label className="flex items-center gap-2 text-body-sm">
            <span className="text-secondary">{t("cart.warrantyLabel")}</span>
            <select
              value={line.warranty?.plan_id ?? ""}
              onChange={(e) => warranty.mutate({ id: line.listing_id, plan: e.target.value ? Number(e.target.value) : null })}
              className="h-8 rounded-md border border-line bg-surface px-2"
            >
              <option value="">{t("product.warrantyNone")}</option>
              {line.warranty_options.map((o) => (
                <option key={o.plan_id} value={o.plan_id}>
                  {o.name} · {formatMoney(o.price)}
                </option>
              ))}
            </select>
          </label>
        ) : null}
        <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <span className="flex flex-wrap items-center gap-3">
            <button type="button" onClick={() => remove.mutate(line.listing_id)} disabled={busy} className="flex items-center gap-1 text-body-sm text-secondary hover:text-ink">
              <Trash2 className="size-4" />
              {t("common.remove")}
            </button>
            {line.product_slug ? <MoveToWishlist slug={line.product_slug} listingId={line.listing_id} disabled={busy} /> : null}
          </span>
          <div className="flex h-10 items-center rounded-md border border-line-strong" role="group" aria-label={t("product.quantity")}>
            <button type="button" className="px-3" disabled={busy} onClick={() => setQty.mutate({ id: line.listing_id, qty: line.quantity - 1 })} aria-label={t("cart.decrease")}>
              <Minus className="size-4" />
            </button>
            <span className="w-8 text-center text-body font-medium tabular-nums">{line.quantity}</span>
            <button type="button" className="px-3" disabled={busy || line.quantity >= 50} onClick={() => setQty.mutate({ id: line.listing_id, qty: line.quantity + 1 })} aria-label={t("cart.increase")}>
              <Plus className="size-4" />
            </button>
          </div>
        </div>
        {setQty.error || remove.error ? <p className="text-body-sm text-danger-text">{(setQty.error ?? remove.error)?.message}</p> : null}
      </div>
    </li>
  );
}

/** Made-to-order measurements edited in the cart (`measurement_fields`, `PUT cart/items/{id}/measurements`); opens by itself while missing. */
function CartMeasurements({ line, labelOf }: { line: CartLine; labelOf: (key: string) => string }) {
  const t = useTranslations();
  const tm = useTranslations("measurements");
  const saved = line.measurements && !Array.isArray(line.measurements) ? line.measurements : {};
  const [open, setOpen] = useState(line.measurements_missing);
  const [values, setValues] = useState<MeasurementValues>(() => Object.fromEntries(Object.entries(saved).map(([k, v]) => [k, String(v ?? "")])));
  const [note, setNote] = useState(line.measurement_note ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const save = useCartMutation((m, v: { values: MeasurementValues; note: string }) => api.setCartMeasurements(m, line.listing_id, measurementPayload(v.values), v.note));
  const entries = Object.entries(saved);

  const submit = () => {
    const found = validateMeasurements(line.measurement_fields, values, (key, vals) => tm(key, vals as Record<string, string>));
    setErrors(found);
    if (Object.keys(found).length) return;
    save.mutate({ values, note }, { onSuccess: () => setOpen(false), onError: (e) => setErrors(fieldErrors(e)) });
  };

  return (
    <div id={`measurements-${line.listing_id}`} className="flex flex-col gap-2">
      {line.measurements_missing ? <p className="text-body-sm font-medium text-warning-text">{t("measurements.missing")}</p> : null}
      {!open && entries.length > 0 ? (
        <div className="rounded-md bg-subtle px-3 py-2 text-body-sm">
          <p className="font-medium">{t("measurements.inCart")}</p>
          <p className="text-secondary">
            {entries.map(([k, v]) => `${labelOf(k)}: ${String(v)}`).join(" · ")}
            {line.measurement_note ? ` — ${line.measurement_note}` : ""}
          </p>
        </div>
      ) : null}
      {open ? (
        <>
          <MeasurementsForm fields={line.measurement_fields} values={values} note={note} errors={errors} onChange={setValues} onNoteChange={setNote} />
          <div className="flex gap-2">
            <Button size="sm" onClick={submit} disabled={save.isPending}>
              {t("measurements.save")}
            </Button>
            {!line.measurements_missing ? (
              <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                {t("measurements.cancel")}
              </Button>
            ) : null}
          </div>
          {save.error && Object.keys(fieldErrors(save.error)).length === 0 ? <p className="text-body-sm text-danger-text">{errorMessage(save.error, t("common.error"))}</p> : null}
        </>
      ) : (
        <Button size="sm" variant="link" className="self-start" onClick={() => setOpen(true)}>
          {t("measurements.edit")}
        </Button>
      )}
    </div>
  );
}

/** Delivery method for the cart (PUT cart/shipping {method}); options and fees per shipment come from the quote. */
export function ShippingMethods({ cart }: { cart: Cart }) {
  const t = useTranslations();
  const shipping = useCartMutation((m, method: string) => api.setCartShipping(m, { city: cart.destination.city, area: cart.destination.area, method }));
  const options = cart.shipments[0]?.shipping_options ?? [];
  if (options.length <= 1) return null;
  const current = cart.shipments[0]?.method;
  return (
    <fieldset className="mb-4 flex flex-col gap-2">
      <legend className="mb-1 text-body font-semibold">{t("cart.deliveryMethod")}</legend>
      {options.map((o) => (
        <label key={o.method} className="flex items-center gap-2 text-body-sm">
          <input type="radio" name="method" checked={current === o.method} onChange={() => shipping.mutate(o.method)} disabled={shipping.isPending} />
          <span className="flex-1">{t.has(`checkout.method.${o.method}` as "checkout.method.standard") ? t(`checkout.method.${o.method}` as "checkout.method.standard") : o.method}</span>
          <span className="font-medium">{Number(o.fee.amount) === 0 ? t("common.free") : formatMoney(o.fee)}</span>
        </label>
      ))}
    </fieldset>
  );
}


/** QC-059 (Figma 25:275 "Remove · Move to wishlist"): save the product, then drop the line. */
function MoveToWishlist({ slug, listingId, disabled }: { slug: string; listingId: number; disabled: boolean }) {
  const t = useTranslations();
  const market = useMarket();
  const client = useQueryClient();
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const remove = useCartMutation((m, id: number) => api.removeCartItem(m, id));
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const move = async () => {
    if (!isSignedIn) return toLogin();
    setPending(true);
    setError(null);
    try {
      await api.addToWishlist(market.slug, slug);
      client.invalidateQueries({ queryKey: queryKeys.wishlist(market.slug) });
      await remove.mutateAsync(listingId);
    } catch (e) {
      setError(errorMessage(e, t("common.error")));
    } finally {
      setPending(false);
    }
  };
  return (
    <>
      <button type="button" onClick={move} disabled={disabled || pending} className="flex items-center gap-1 text-body-sm text-secondary hover:text-ink">
        <Heart className="size-4" />
        {t("cart.moveToWishlist")}
      </button>
      {error ? <span role="alert" className="text-body-sm text-danger-text">{error}</span> : null}
    </>
  );
}
