"use client";

import { useQuery } from "@tanstack/react-query";
import { CreditCard } from "lucide-react";
import { useTranslations } from "next-intl";
import * as api from "@/lib/api/endpoints";
import type { Money } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { useMarket } from "@/lib/market-context";

/** Split an amount string into `parts` equal instalments (rounded up to the currency's minor unit), without floats. */
export function instalment(amount: string, parts: number, decimals: number): string {
  const [whole, frac = ""] = amount.split(".");
  const minor = BigInt(whole + frac.padEnd(decimals, "0").slice(0, decimals));
  const each = (minor + BigInt(parts) - BigInt(1)) / BigInt(parts);
  const text = each.toString().padStart(decimals + 1, "0");
  return decimals > 0 ? `${text.slice(0, -decimals)}.${text.slice(-decimals)}` : text;
}

/**
 * "Split into 4 payments of …" (Figma 24:2, QC-057) when a pay-later method
 * (Tabby / Tamara) is enabled in this country for the price.
 */
export function BnplLine({ price }: { price: Money | null }) {
  const t = useTranslations("discovery.pdp");
  const market = useMarket();
  const amount = price?.amount ?? null;
  const { data } = useQuery({
    queryKey: ["payment-methods", market.slug, amount],
    enabled: !!amount,
    staleTime: 5 * 60_000,
    queryFn: async () => (await api.getPaymentMethods(market.slug, amount!)).data,
  });
  const bnpl = data?.filter((m) => m.type === "bnpl") ?? [];
  if (!price || bnpl.length === 0) return null;
  const parts = bnpl.some((m) => m.code === "tabby") ? 4 : 3;
  const each = { amount: instalment(price.amount, parts, market.country.decimals), currency: price.currency };
  return (
    <p className="flex items-start gap-2 rounded-md bg-info-subtle px-3 py-2 text-body-sm">
      <CreditCard className="mt-0.5 size-4 shrink-0 text-link" />
      <span>
        {t("bnpl", { count: parts, amount: formatMoney(each) })} <span className="text-secondary">· {bnpl.map((m) => m.name.split("·")[0].trim()).join(" / ")}</span>
      </span>
    </p>
  );
}
