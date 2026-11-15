"use client";

import { useQuery } from "@tanstack/react-query";
import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useEffect } from "react";
import { Skeleton } from "@/components/ui/misc";
import { getPaymentMethods } from "@/lib/api/endpoints";
import type { PaymentMethod } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { useMarket } from "@/lib/market-context";

/** Methods that cannot pay for a gift card or a membership (no cash, no credits). */
const NOT_FOR_DIGITAL = new Set(["cod", "cash_on_delivery", "wallet"]);

/**
 * Payment method picker for gift cards and Nawy One, listed by the API per
 * country and amount (`GET checkout/payment-methods`), as on checkout.
 */
export function PaymentMethodPicker({ amount, value, onChange, label }: { amount: string | null; value: string | null; onChange: (code: string | null) => void; label: string }) {
  const t = useTranslations("shop.giftCards");
  const market = useMarket();
  const valid = !!amount && Number(amount) > 0;
  const { data, isLoading } = useQuery({
    queryKey: ["payment-methods", market.slug, amount],
    enabled: valid,
    queryFn: async () => (await getPaymentMethods(market.slug, amount!)).data.filter((m: PaymentMethod) => !NOT_FOR_DIGITAL.has(m.code) && m.type !== "cod" && m.type !== "wallet"),
  });

  useEffect(() => {
    if (data && !data.some((m) => m.code === value)) onChange(data.find((m) => m.recommended)?.code ?? data[0]?.code ?? null);
  }, [data, value, onChange]);

  if (!valid) return null;
  return (
    <fieldset className="flex flex-col gap-2">
      <legend className="mb-1.5 text-label font-medium">{label}</legend>
      {isLoading ? <Skeleton className="h-14 w-full" /> : null}
      {data && data.length === 0 ? <p className="text-body-sm text-danger-text">{t("noMethods")}</p> : null}
      {data?.map((m) => (
        <label key={m.code} className={clsx("flex cursor-pointer items-center gap-3 rounded-md border p-3", value === m.code ? "border-brand bg-brand-subtle" : "border-line")}>
          <input type="radio" name="payment_method" checked={value === m.code} onChange={() => onChange(m.code)} />
          <span className="flex-1">
            <span className="block text-body font-medium">{m.name}</span>
            {m.description ? <span className="block text-body-sm text-secondary">{m.description}</span> : null}
          </span>
          {m.fee && Number(m.fee.amount) > 0 ? <span className="text-body-sm text-secondary">+ {formatMoney(m.fee)}</span> : null}
        </label>
      ))}
    </fieldset>
  );
}

type NextAction = { type: string; url?: string };

/** Follow a payment's `next_action`; returns true when the browser is leaving for the gateway. */
export function followNextAction(action: NextAction | null | undefined): boolean {
  if (action?.type === "redirect" && action.url && /^https?:\/\//.test(action.url)) {
    window.location.href = action.url;
    return true;
  }
  return false;
}
