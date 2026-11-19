"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { BarChart3, Calculator, Check, Heart, Share2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import * as api from "@/lib/api/classifieds";
import type { Money } from "@/lib/api/schemas";
import { formatMoney } from "@/lib/format";
import { useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";
import { favouritesKey, useFavouriteNumbers } from "./ad-card";

/** Ads picked for comparison on this device, per market (max 4, same category enforced by the API). */
const COMPARE_KEY = (market: string) => `nawy_classified_compare_${market}`;
const COMPARE_MAX = 4;

function readCompare(market: string): string[] {
  try {
    const raw = window.localStorage.getItem(COMPARE_KEY(market));
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((n): n is string => typeof n === "string").slice(0, COMPARE_MAX) : [];
  } catch {
    return [];
  }
}

function writeCompare(market: string, numbers: string[]) {
  try {
    window.localStorage.setItem(COMPARE_KEY(market), JSON.stringify(numbers.slice(0, COMPARE_MAX)));
  } catch {
    // Storage disabled: the compare link still carries this ad.
  }
}

const actionClass = "inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-body-sm font-medium text-ink hover:bg-subtle disabled:opacity-60";

/**
 * Title-card actions of an ad (Figma Classified ad detail 55:1477, QC-061):
 * Save (with the save count), Share (Web Share API, copy-link fallback) and
 * Compare (a per-device list of up to 4 ads → /classified/compare).
 */
export function AdTitleActions({ number, title, saves, live }: { number: string; title: string; saves: number; live: boolean }) {
  const t = useTranslations("portalPlus.ad");
  const tc = useTranslations("classifieds");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const { isSignedIn } = useSession();
  const favourites = useFavouriteNumbers();
  const savedServer = !!favourites.data?.some((ad) => ad.number === number);
  const [delta, setDelta] = useState(0);
  const [copied, setCopied] = useState(false);
  const [compare, setCompare] = useState<string[]>([]);

  useEffect(() => setCompare(readCompare(market.slug)), [market.slug]);

  const toggle = useMutation({
    mutationFn: async (add: boolean) => (add ? api.addFavourite(market.slug, number) : api.removeFavourite(market.slug, number)),
    onSuccess: (_data, add) => setDelta((d) => d + (add ? 1 : -1)),
    onSettled: () => client.invalidateQueries({ queryKey: favouritesKey(market.slug) }),
  });

  const save = () => {
    if (!isSignedIn) {
      router.push(`${href("/login")}?next=${encodeURIComponent(window.location.pathname)}`);
      return;
    }
    toggle.mutate(!savedServer);
  };

  const share = async () => {
    const url = window.location.href;
    if (typeof navigator.share === "function") {
      try {
        await navigator.share({ title, url });
        return;
      } catch {
        // Cancelled or refused: fall back to copying the link.
      }
    }
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      window.prompt(t("copyLink"), url);
    }
  };

  const inCompare = compare.includes(number);
  const toggleCompare = () => {
    const next = inCompare ? compare.filter((n) => n !== number) : [...compare.filter((n) => n !== number), number].slice(-COMPARE_MAX);
    setCompare(next);
    writeCompare(market.slug, next);
  };

  const count = Math.max(0, saves + delta);

  return (
    <div className="flex flex-wrap items-center gap-1">
      {live ? (
        <button type="button" className={actionClass} onClick={save} disabled={toggle.isPending} aria-pressed={savedServer}>
          <Heart className={clsx("size-4", savedServer && "fill-danger-text text-danger-text")} />
          {savedServer ? tc("saved") : tc("save")} ({count})
        </button>
      ) : null}
      <button type="button" className={actionClass} onClick={share}>
        {copied ? <Check className="size-4 text-success-text" /> : <Share2 className="size-4" />}
        {copied ? t("linkCopied") : t("share")}
      </button>
      {live ? (
        <button type="button" className={actionClass} onClick={toggleCompare} aria-pressed={inCompare}>
          <BarChart3 className="size-4" />
          {inCompare ? t("inCompare") : tc("compare.cta")}
        </button>
      ) : null}
      {compare.length >= 2 ? (
        <Link href={`${href("/classified/compare")}?numbers=${compare.map(encodeURIComponent).join(",")}`} className="ms-1 text-body-sm font-semibold text-link">
          {t("compareNow", { count: compare.length })}
        </Link>
      ) : null}
    </div>
  );
}

/** Indicative finance terms (QC-061 financing card): 20 % down, 60 months, flat 4.99 % a year. Not an offer. */
const FINANCE = { downPercent: 20, months: 60, annualRatePercent: 4.99 };

/** Monthly instalment in minor-unit-free display terms (indicative only, so rounding to whole units is fine). */
export function monthlyInstalment(price: number): number {
  const principal = price * (1 - FINANCE.downPercent / 100);
  const r = FINANCE.annualRatePercent / 100 / 12;
  const n = FINANCE.months;
  return Math.round((principal * r) / (1 - Math.pow(1 + r, -n)));
}

/**
 * "Est. AED 4,380 / month" card (Figma 55:1477 right rail) for priced
 * vehicle and property ads. Indicative only: Nawy does not offer finance.
 */
export function FinancingCard({ price }: { price: Money }) {
  const t = useTranslations("portalPlus.ad");
  const amount = Number(price.amount);
  if (!Number.isFinite(amount) || amount <= 0) return null;
  const monthly: Money = { amount: String(monthlyInstalment(amount)), currency: price.currency };
  return (
    <section className="flex flex-col gap-1 rounded-lg border border-line bg-surface p-4">
      <p className="flex items-center gap-2 text-body font-semibold">
        <Calculator className="size-4 text-brand-text" />
        {t("financeMonthly", { amount: formatMoney(monthly) })}
      </p>
      <p className="text-caption text-secondary">{t("financeTerms", { months: FINANCE.months, down: FINANCE.downPercent, rate: FINANCE.annualRatePercent })}</p>
    </section>
  );
}
