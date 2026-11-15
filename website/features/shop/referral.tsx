"use client";

import { useQuery } from "@tanstack/react-query";
import { Link2Off } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { ProductCard } from "@/components/product/product-card";
import { Button, ButtonLink } from "@/components/ui/button";
import { EmptyState, Skeleton } from "@/components/ui/misc";
import { ApiError } from "@/lib/api/client";
import { cardFromApi } from "@/lib/api/mappers";
import * as api from "@/lib/api/shop";
import { errorMessage, useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/** Referral code kept for checkout (`POST orders` accepts `referral_code`). */
export const REFERRAL_STORAGE_KEY = "nawy_referral_code";
const VALID = new Set(["active", "pending", "grace"]);

/**
 * `/r/{code}` (Figma Referral landing 71:11488). The request goes through the
 * BFF proxy so the click is recorded with the visitor token (and the
 * customer when signed in); the page then sends the visitor to the product.
 */
export function ReferralLanding({ code }: { code: string }) {
  const t = useTranslations("shop.referral");
  const tc = useTranslations("common");
  const market = useMarket();
  const href = useHref();
  const { user } = useSession();
  const [copied, setCopied] = useState(false);
  const referral = useQuery({
    queryKey: ["referral", market.slug, code],
    retry: false,
    staleTime: Infinity,
    queryFn: async () => (await api.getReferral(market.slug, code)).data,
  });
  const valid = referral.data && VALID.has(referral.data.status);

  useEffect(() => {
    if (!valid) return;
    try {
      window.localStorage.setItem(REFERRAL_STORAGE_KEY, JSON.stringify({ code: referral.data!.share.code, market: market.slug, at: Date.now() }));
    } catch {
      // Storage disabled: attribution still works through the visitor token.
    }
  }, [valid, referral.data, market.slug]);

  if (referral.isLoading) return <Skeleton className="h-64 w-full" />;
  if (referral.error) {
    const missing = referral.error instanceof ApiError && referral.error.status === 404;
    return (
      <EmptyState
        icon={<Link2Off className="size-10" />}
        title={missing ? t("notFound") : errorMessage(referral.error, tc("error"))}
        body={t("invalidHint")}
        action={<ButtonLink href={href("/")}>{tc("goHome")}</ButtonLink>}
      />
    );
  }
  const data = referral.data!;
  if (!valid) {
    return <EmptyState icon={<Link2Off className="size-10" />} title={t("invalid")} body={t("invalidHint")} action={<ButtonLink href={href("/")}>{tc("goHome")}</ButtonLink>} />;
  }

  const name = data.creator.name ?? "Nawy";
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
  const productHref = href(`/products/${encodeURIComponent(data.destination.product_slug)}`);
  const cards = [data.destination, ...data.picks.filter((pick) => (pick.product_slug ?? pick.slug) !== data.destination.product_slug)].map((card) => ({
    ...cardFromApi({ ...card, slug: card.product_slug ?? card.slug }),
  }));

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(data.share.code);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <section className="flex flex-wrap items-center gap-6 rounded-xl bg-brand-subtle p-6 md:p-10">
        <span className="flex size-20 shrink-0 items-center justify-center rounded-full bg-surface text-h2 font-bold text-brand-text">{initials}</span>
        <div className="min-w-0 flex-1">
          <h1 className="text-h1 font-bold">{t("invited", { name })}</h1>
          {data.campaign.title ? <p className="mt-1 text-body text-secondary">{t("campaign", { title: data.campaign.title })}</p> : null}
          <p className="mt-1 text-body-sm text-secondary">{t("applied")}</p>
          <div className="mt-4 flex flex-wrap gap-3">
            <ButtonLink href={productHref}>{t("shop", { name })}</ButtonLink>
            {!user ? (
              <ButtonLink variant="outline" href={`${href("/login")}?next=${encodeURIComponent(productHref)}`}>
                {t("createAccount")}
              </ButtonLink>
            ) : null}
          </div>
        </div>
        <div className="flex w-full flex-col items-center gap-1 rounded-lg border border-line bg-surface p-5 text-center sm:w-56">
          <span className="text-caption text-secondary">{t("yourCode")}</span>
          <span className="text-h3 font-bold" dir="ltr">
            {data.share.code}
          </span>
          <Button variant="link" size="sm" onClick={copy}>
            {copied ? t("copied") : t("copy")}
          </Button>
        </div>
      </section>

      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("pickedBy", { name })}</h2>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-5">
          {cards.map((card, i) => (
            <ProductCard key={`${card.slug}-${i}`} product={card} />
          ))}
        </div>
        <p className="mt-3 text-caption text-secondary">{t("disclosure", { name })}</p>
      </section>
    </div>
  );
}
