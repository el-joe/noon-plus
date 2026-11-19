"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { ClipboardCheck, Info, Lock, MapPin, Truck } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useMemo, useRef, useState } from "react";
import { ButtonLink, Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { Alert, Card, EmptyState, ProductImage, Skeleton } from "@/components/ui/misc";
import { AddressFormModal, formatAddress } from "@/features/account/address-form";
import { CartWarnings, CouponBox, Totals } from "@/features/cart/summary";
import { DeliveryPromise } from "@/features/product/delivery-promise";
import { ApiError, fieldErrors } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { requiredContract, type Order, type RequiredContract } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { errorMessage, queryKeys, useAddresses, useCart, useCartMutation, useMounted, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { REFERRAL_STORAGE_KEY } from "@/features/shop/referral";
import { ContractGate } from "./contract-gate";

const REFERRAL_DAYS = 30;

/** Creator code kept by `/r/{code}` for this market (30 days); `POST orders` credits it as direct attribution. */
function storedReferralCode(market: string): string | null {
  try {
    const raw = window.localStorage.getItem(REFERRAL_STORAGE_KEY);
    if (!raw) return null;
    const saved = JSON.parse(raw) as { code?: unknown; market?: unknown; at?: unknown };
    if (typeof saved.code !== "string" || saved.market !== market || typeof saved.at !== "number") return null;
    return Date.now() - saved.at < REFERRAL_DAYS * 86_400_000 ? saved.code.slice(0, 64) : null;
  } catch {
    return null;
  }
}

function clearReferralCode() {
  try {
    window.localStorage.removeItem(REFERRAL_STORAGE_KEY);
  } catch {
    // Storage disabled.
  }
}

const EMPTY_ADDRESS: api.AddressInput = { name: "", phone: "", city: "", area: "", street: "", building: "" };

/** Where to send the customer after POST orders, from `payment.next_action`. */
export function followPayment(order: Order, href: (p: string) => string): string {
  const action = order.payment?.next_action;
  if (action?.type === "redirect" && action.url && /^https?:\/\//.test(action.url)) return action.url;
  if (action?.type === "retry" || action?.type === "wait") return href(`/checkout/payment?order=${encodeURIComponent(order.number)}`);
  return href(`/checkout/success?order=${encodeURIComponent(order.number)}`);
}

/**
 * `/checkout` (Figma Checkout desktop 25:605; gates 102:16536 / 102:16764):
 * address → delivery per shipment → payment methods from the API (per country
 * and amount) → review (QC-058: 4 steps) → place order → follow
 * `payment.next_action`. The minimal "Secure checkout" header is
 * <CheckoutChrome> on the page.
 */
export function CheckoutView() {
  const t = useTranslations();
  const tf = useTranslations("portalPlus.checkout");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const mounted = useMounted();
  const { user, isLoading: sessionLoading } = useSession();
  const book = useAddresses();
  const saved = useMemo(() => book.data ?? [], [book.data]);

  const [address, setAddress] = useState<api.AddressInput>(EMPTY_ADDRESS);
  const [savedId, setSavedId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [saveAddress, setSaveAddress] = useState(true);
  const [method, setMethod] = useState<string | null>(null);
  const [placing, setPlacing] = useState(false);
  // QC-044: one Idempotency-Key per order attempt; kept only when the outcome is unknown (network error, 5xx), so a retry can never place a second order.
  const attemptKey = useRef<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [contracts, setContracts] = useState<RequiredContract[] | null>(null);
  const [stage, setStage] = useState<"details" | "review">("details");

  const { data: cart, isLoading: cartLoading } = useCart({ force: !!user, paymentMethod: method ?? undefined });
  const total = cart?.totals.total.amount;

  const methods = useQuery({
    queryKey: ["payment-methods", market.slug, total],
    enabled: !!total && Number(total) > 0,
    queryFn: async () => (await api.getPaymentMethods(market.slug, total!)).data,
  });
  const wallet = useQuery({
    queryKey: ["wallet", market.slug],
    enabled: !!user,
    retry: false,
    queryFn: async () => (await api.getWallet(market.slug)).data,
  });

  // Preselect the default saved address and the recommended payment method.
  const chosen = saved.find((a) => a.id === savedId) ?? null;
  useEffect(() => {
    if (savedId === null && saved.length > 0 && address === EMPTY_ADDRESS) {
      setSavedId((saved.find((a) => a.is_default) ?? saved[0]).id);
    }
  }, [saved, savedId, address]);
  useEffect(() => {
    if (!method && methods.data?.length) setMethod((methods.data.find((m) => m.recommended) ?? methods.data[0]).code);
  }, [methods.data, method]);

  // Quote shipping for the chosen city / area.
  const shipping = useCartMutation((m, body: { city: string; area: string }) => api.setCartShipping(m, body));
  const { mutate: quoteShipping } = shipping;
  const destination = chosen ? { city: chosen.city ?? "", area: chosen.area ?? "" } : { city: address.city, area: address.area };
  useEffect(() => {
    if (!cart || !destination.city || !destination.area) return;
    if (cart.destination.city === destination.city && cart.destination.area === destination.area) return;
    const timer = window.setTimeout(() => quoteShipping({ city: destination.city, area: destination.area }), 600);
    return () => window.clearTimeout(timer);
  }, [destination.city, destination.area, cart, quoteShipping]);

  const addressComplete = useMemo(
    () => !!chosen || !!(address.name.trim() && address.city.trim() && address.area.trim() && address.street.trim()),
    [address, chosen],
  );

  if (!mounted || sessionLoading) return <Skeleton className="h-[480px] w-full" />;

  if (!user) {
    return (
      <EmptyState
        icon={<Lock className="size-10" />}
        title={t("checkout.signInTitle")}
        body={t("checkout.signInHint")}
        action={<ButtonLink href={`${href("/login")}?next=${encodeURIComponent(href("/checkout"))}`}>{t("checkout.signIn")}</ButtonLink>}
      />
    );
  }

  if (cartLoading || book.isLoading) return <Skeleton className="h-[480px] w-full" />;
  const lines = cart?.shipments.flatMap((s) => s.items) ?? [];
  const missingMeasurements = lines.filter((l) => l.measurements_missing).length;
  if (!cart || lines.length === 0) {
    return <EmptyState title={t("checkout.emptyCart")} action={<ButtonLink href={href("/")}>{t("cart.startShopping")}</ButtonLink>} />;
  }

  const set = (key: keyof api.AddressInput) => (e: React.ChangeEvent<HTMLInputElement>) => setAddress({ ...address, [key]: e.target.value });

  const place = async () => {
    setError(null);
    if (!addressComplete) return setError(t("checkout.fillAddress"));
    if (!method) return setError(t("checkout.choosePayment"));
    setPlacing(true);
    setFields({});
    try {
      let addressId = chosen?.id ?? null;
      const clean = Object.fromEntries(Object.entries(address).map(([k, v]) => [k, typeof v === "string" ? v.trim() : v]).filter(([, v]) => v !== "")) as api.AddressInput;
      // A new address is saved to the account book first (phone required there), then used by id.
      if (!addressId && saveAddress) {
        try {
          const { data: created } = await api.createAddress(market.slug, { ...clean, phone: clean.phone ?? "" });
          addressId = created.id;
          client.invalidateQueries({ queryKey: queryKeys.addresses(market.slug) });
        } catch (e) {
          const errors = fieldErrors(e);
          if (Object.keys(errors).length > 0) {
            setFields(errors);
            setPlacing(false);
            return;
          }
          throw e;
        }
      }
      const referral_code = storedReferralCode(market.slug);
      attemptKey.current ??= newIdempotencyKey();
      const { data: order } = await api
        .placeOrder(
          market.slug,
          {
            payment_method: method,
            ...(addressId ? { address_id: addressId } : { address: clean }),
            ...(referral_code ? { referral_code } : {}),
          },
          attemptKey.current,
        )
        .catch((e: unknown) => {
          if (e instanceof ApiError && e.status < 500) attemptKey.current = null;
          throw e;
        });
      attemptKey.current = null;
      clearReferralCode();
      client.removeQueries({ queryKey: queryKeys.cart(market.slug) });
      const next = followPayment(order, href);
      if (next.startsWith("http")) window.location.href = next;
      else router.push(next);
    } catch (e) {
      if (e instanceof ApiError && e.code === "customer_contract_required") {
        const parsed = requiredContract.array().safeParse(e.details.contracts);
        setContracts(parsed.success ? parsed.data : []);
      } else if (e instanceof ApiError && e.code === "measurements_required") {
        setError(t("measurements.missingBeforeCheckout", { count: Math.max(1, missingMeasurements) }));
        client.invalidateQueries({ queryKey: queryKeys.cart(market.slug) });
      } else if (e instanceof ApiError && e.code === "email_not_verified") {
        setError(t("checkout.emailNotVerified"));
      } else {
        const errors = fieldErrors(e);
        setFields(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k.replace(/^address\./, ""), v])));
        setError(errorMessage(e, t("common.error")));
      }
      setPlacing(false);
    }
  };

  const reviewing = stage === "review";
  const toReview = () => {
    setError(null);
    if (!addressComplete) return setError(t("checkout.fillAddress"));
    if (!method) return setError(t("checkout.choosePayment"));
    setStage("review");
    window.scrollTo({ top: 0, behavior: "smooth" });
  };
  const backToDetails = () => {
    setStage("details");
    setError(null);
  };
  const methodName = methods.data?.find((m) => m.code === method)?.name ?? method ?? "";
  const addressLine = chosen ? `${chosen.name}${chosen.phone ? ` · ${chosen.phone}` : ""} — ${formatAddress(chosen)}` : [address.name, address.phone, address.street, address.building, address.area, address.city].filter(Boolean).join(", ");

  // Figma 25:605: Address · Delivery · Payment · Review, each Completed / In progress / Not started.
  const steps = [
    { label: t("checkout.stepAddress"), done: addressComplete },
    { label: t("checkout.stepDelivery"), done: addressComplete },
    { label: t("checkout.stepPayment"), done: !!method && reviewing },
    { label: tf("stepReview"), done: false },
  ];
  const current = steps.findIndex((step) => !step.done);

  return (
    <div className="flex flex-col gap-5">
      <h1 className="sr-only">{t("checkout.title")}</h1>
      <ol className="flex flex-wrap gap-x-8 gap-y-3" aria-label={tf("stepsLabel")}>
        {steps.map((step, i) => {
          const state = step.done ? "done" : i === current ? "current" : "todo";
          return (
            <li key={step.label} className="flex items-center gap-2.5" aria-current={state === "current" ? "step" : undefined}>
              <span
                className={clsx(
                  "flex size-7 items-center justify-center rounded-full text-body-sm font-semibold",
                  state === "done" ? "bg-success text-white" : state === "current" ? "bg-brand text-ink" : "border border-line-strong text-secondary",
                )}
              >
                {state === "done" ? "✓" : i + 1}
              </span>
              <span className="flex flex-col">
                <span className={clsx("text-body font-medium", state === "todo" && "text-secondary")}>{step.label}</span>
                <span className="text-caption text-secondary">{tf(`state.${state}`)}</span>
              </span>
            </li>
          );
        })}
      </ol>

      <div className="grid items-start gap-6 lg:grid-cols-[1fr_340px]">
        {reviewing ? (
          <div className="flex flex-col gap-4">
            <Card>
              <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-h4 font-semibold">
                  <MapPin className="size-5 text-brand-text" /> {t("checkout.addressTitle")}
                </h2>
                <button type="button" className="text-body-sm text-link" onClick={backToDetails}>
                  {tf("change")}
                </button>
              </div>
              <p className="text-body text-secondary">{addressLine}</p>
            </Card>
            <Card>
              <h2 className="mb-3 flex items-center gap-2 text-h4 font-semibold">
                <ClipboardCheck className="size-5 text-brand-text" /> {tf("reviewItems")}
              </h2>
              <div className="flex flex-col gap-4">
                {cart.shipments.map((s, i) => (
                  <div key={i} className="rounded-md border border-line">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-subtle px-3 py-2">
                      <p className="text-body font-medium">
                        {t("cart.shipmentOf", { n: i + 1, total: cart.shipments.length })} · {s.seller}
                      </p>
                      <DeliveryPromise delivery={s.delivery} express={s.method === "express"} />
                    </div>
                    <ul className="divide-y divide-line">
                      {s.items.map((l) => (
                        <li key={l.listing_id} className="flex items-center gap-3 px-3 py-2.5">
                          <div className="size-12 shrink-0 overflow-hidden rounded-md border border-line">
                            <ProductImage src={l.image?.url} alt={l.title} />
                          </div>
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-body">{l.title}</p>
                            <p className="text-body-sm text-secondary">{tf("quantity", { count: l.quantity })}</p>
                          </div>
                          <span className="text-body font-medium">{formatMoney(l.line_total)}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            </Card>
            <Card>
              <div className="flex items-center justify-between gap-3">
                <h2 className="text-h4 font-semibold">{t("checkout.paymentTitle")}</h2>
                <button type="button" className="text-body-sm text-link" onClick={backToDetails}>
                  {tf("change")}
                </button>
              </div>
              <p className="mt-2 text-body text-secondary">{methodName}</p>
            </Card>
          </div>
        ) : (
        <div className="flex flex-col gap-4">
          <Card>
            <h2 className="mb-3 flex items-center gap-2 text-h4 font-semibold">
              <MapPin className="size-5 text-brand-text" /> {t("checkout.addressTitle")}
            </h2>
            {saved.length > 0 ? (
              <div className="flex flex-col gap-2">
                <p className="text-label font-medium text-secondary">{t("checkout.savedAddresses")}</p>
                {saved.map((a) => (
                  <label key={a.id} className={clsx("flex cursor-pointer items-start gap-3 rounded-md border p-3", savedId === a.id ? "border-brand bg-brand-subtle" : "border-line")}>
                    <input type="radio" name="address" checked={savedId === a.id} onChange={() => setSavedId(a.id)} className="mt-1" />
                    <span className="text-body">
                      <span className="block font-medium">
                        {a.name}
                        {a.phone ? <span dir="ltr"> · {a.phone}</span> : null}
                      </span>
                      <span className="text-secondary">{formatAddress(a)}</span>
                    </span>
                  </label>
                ))}
                <button type="button" className="self-start text-body-sm text-link" onClick={() => setAdding(true)}>
                  + {t("checkout.newAddress")}
                </button>
              </div>
            ) : null}
            {saved.length === 0 ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t("checkout.name")} error={fields.name}>
                  <Input value={address.name} onChange={set("name")} required maxLength={120} autoComplete="name" />
                </Field>
                <Field label={t("checkout.phone")} error={fields.phone}>
                  <Input value={address.phone ?? ""} onChange={set("phone")} maxLength={32} type="tel" autoComplete="tel" dir="ltr" />
                </Field>
                <Field label={t("checkout.city")} error={fields.city}>
                  <Input value={address.city} onChange={set("city")} required maxLength={120} autoComplete="address-level2" />
                </Field>
                <Field label={t("checkout.area")} error={fields.area}>
                  <Input value={address.area} onChange={set("area")} required maxLength={120} autoComplete="address-level3" />
                </Field>
                <Field label={t("checkout.street")} className="sm:col-span-2" error={fields.street}>
                  <Input value={address.street} onChange={set("street")} required maxLength={255} autoComplete="street-address" />
                </Field>
                <Field label={`${t("checkout.building")} (${t("common.optional")})`} error={fields.building}>
                  <Input value={address.building ?? ""} onChange={set("building")} maxLength={120} />
                </Field>
                <label className="flex items-center gap-2 self-end text-body-sm">
                  <input type="checkbox" checked={saveAddress} onChange={(e) => setSaveAddress(e.target.checked)} />
                  {t("checkout.saveAddress")}
                </label>
              </div>
            ) : null}
          </Card>

          <Card>
            <h2 className="mb-3 flex items-center gap-2 text-h4 font-semibold">
              <Truck className="size-5 text-brand-text" /> {t("checkout.deliveryTitle", { count: cart.shipments.length })}
            </h2>
            <ul className="flex flex-col gap-2">
              {cart.shipments.map((s, i) => (
                <li key={i} className="flex items-center justify-between gap-3 rounded-md bg-subtle px-3 py-2.5">
                  <div>
                    <p className="text-body font-medium">
                      {t("cart.shipmentOf", { n: i + 1, total: cart.shipments.length })} · {s.seller}
                    </p>
                    <DeliveryPromise delivery={s.delivery} express={s.method === "express"} />
                  </div>
                  <span className="text-body-sm text-secondary">{t("common.items", { count: s.items.reduce((n, l) => n + l.quantity, 0) })}</span>
                </li>
              ))}
            </ul>
          </Card>

          <Card>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-h4 font-semibold">{t("checkout.paymentTitle")}</h2>
              <span className="rounded-full bg-subtle px-2 py-0.5 text-caption text-secondary">
                {t("checkout.showingFor", { country: market.country.name[market.locale], currency: market.country.currency })}
              </span>
            </div>
            {methods.isLoading ? <Skeleton className="h-40 w-full" /> : null}
            {methods.error ? <Alert tone="danger">{errorMessage(methods.error, t("common.unreachable"))}</Alert> : null}
            <div className="flex flex-col gap-2">
              {(methods.data ?? []).map((m) => (
                <label key={m.code} className={clsx("flex cursor-pointer items-center gap-3 rounded-md border p-3", method === m.code ? "border-brand bg-brand-subtle" : "border-line")}>
                  <input type="radio" name="payment" checked={method === m.code} onChange={() => setMethod(m.code)} />
                  <span className="flex h-9 w-16 shrink-0 items-center justify-center rounded-sm border border-line bg-surface text-[10px] font-semibold uppercase text-muted">{m.code}</span>
                  <span className="flex-1">
                    <span className="flex flex-wrap items-center gap-2 text-body font-medium">
                      {m.name}
                      {m.recommended ? <span className="text-caption font-medium text-brand-text">{t("checkout.recommended")}</span> : null}
                    </span>
                    <span className="block text-body-sm text-secondary">
                      {m.code === "wallet" && wallet.data?.available ? t("checkout.walletBalance", { amount: formatMoney(wallet.data.available) }) : m.description}
                      {m.fee && Number(m.fee.amount) > 0 ? ` · ${t("checkout.fee", { amount: formatMoney(m.fee) })}` : ""}
                    </span>
                  </span>
                </label>
              ))}
            </div>
            {wallet.data?.available && !(methods.data ?? []).some((m) => m.code === "wallet") && Number(wallet.data.available.amount) > 0 ? (
              <p className="mt-3 text-body-sm text-secondary">
                {t("checkout.walletTitle")}: {t("checkout.walletBalance", { amount: formatMoney(wallet.data.available) })}
              </p>
            ) : null}
            <p className="mt-2 text-body-sm text-secondary">{t("checkout.giftCardHint")}</p>
            <Alert tone="info" className="mt-4 flex gap-2">
              <Info className="mt-0.5 size-4 shrink-0 text-link" />
              <span className="text-body-sm">{t("checkout.verifyNote")}</span>
            </Alert>
          </Card>
        </div>
        )}

        <aside className="flex flex-col gap-4 lg:sticky lg:top-32">
          <Card>
            <h2 className="mb-3 text-h3 font-semibold">{t("cart.orderSummary")}</h2>
            <div className="mb-4 flex gap-2">
              {lines.slice(0, 4).map((l) => (
                <div key={l.listing_id} className="size-12 overflow-hidden rounded-md border border-line" title={l.title}>
                  <ProductImage src={l.image?.url} alt={l.title} />
                </div>
              ))}
            </div>
            <CouponBox cart={cart} />
            <CartWarnings cart={cart} className="mt-3" />
            <div className="my-4 border-t border-line" />
            <Totals cart={cart} />
            {missingMeasurements > 0 ? (
              <Alert tone="warning" className="mt-4">
                {t("measurements.missingBeforeCheckout", { count: missingMeasurements })}{" "}
                <a href={href("/cart")} className="font-semibold underline">
                  {t("measurements.backToCart")}
                </a>
              </Alert>
            ) : null}
            {error ? <Alert tone="danger" className="mt-4">{error}</Alert> : null}
            {reviewing ? (
              <Button size="lg" className="mt-4 w-full" onClick={place} disabled={placing || shipping.isPending || missingMeasurements > 0}>
                {placing ? t("checkout.placing") : t("checkout.placeOrder", { total: formatMoney(cart.totals.total) })}
              </Button>
            ) : (
              <Button size="lg" className="mt-4 w-full" onClick={toReview} disabled={shipping.isPending || missingMeasurements > 0}>
                {tf("continueToReview")}
              </Button>
            )}
            <p className="mt-3 text-caption text-muted">{t("checkout.terms", { country: market.country.name[market.locale] })}</p>
          </Card>
        </aside>
      </div>

      {adding ? <AddressFormModal onClose={() => setAdding(false)} onSaved={(a) => setSavedId(a.id)} /> : null}

      {contracts ? (
        <ContractGate
          contracts={contracts}
          defaultName={chosen?.name || address.name || user.name || ""}
          onClose={() => setContracts(null)}
          onAccepted={() => {
            setContracts(null);
            void place();
          }}
        />
      ) : null}
    </div>
  );
}

/** A fresh client Idempotency-Key (QC-044). */
function newIdempotencyKey(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? `order-${crypto.randomUUID()}` : `order-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
}
