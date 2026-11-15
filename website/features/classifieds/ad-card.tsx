"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { BadgeCheck, Camera, Heart, MapPin, Video } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Badge, ProductImage } from "@/components/ui/misc";
import * as api from "@/lib/api/classifieds";
import type { AdCard as AdCardData } from "@/lib/api/classifieds";
import { formatAmount, formatDay } from "@/lib/format";
import { useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/** Ad photo (cover crop) or the shared placeholder when the ad has none. */
export function AdImage({ src, alt, className }: { src?: string | null; alt: string; className?: string }) {
  if (!src) return <ProductImage alt={alt} className={className} />;
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={src} alt={alt} loading="lazy" className={clsx("size-full object-cover", className)} />;
}

export const favouritesKey = (market: string) => ["classified-favourites", market] as const;

/** Link to an ad: property ads open in the real-estate vertical. */
export function adPath(ad: { number: string; category?: string | null; property?: unknown }, propertySlugs?: Set<string>): string {
  const isProperty = !!ad.property || (ad.category ? propertySlugs?.has(ad.category) : false);
  return isProperty ? `/property/${encodeURIComponent(ad.number)}` : `/classified/find/${encodeURIComponent(ad.number)}`;
}

/** Favourite numbers of the signed-in customer (shared by every heart on the page). */
export function useFavouriteNumbers() {
  const market = useMarket();
  const { isSignedIn } = useSession();
  return useQuery({
    queryKey: favouritesKey(market.slug),
    enabled: isSignedIn,
    staleTime: 60_000,
    queryFn: async () => (await api.getFavourites(market.slug)).data,
  });
}

/** Heart toggle (Figma Favourites 100:11857); guests are sent to sign in. */
export function FavouriteButton({ number, className, withLabel = false }: { number: string; className?: string; withLabel?: boolean }) {
  const t = useTranslations("classifieds");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const { isSignedIn } = useSession();
  const favourites = useFavouriteNumbers();
  const saved = !!favourites.data?.some((ad) => ad.number === number);
  const toggle = useMutation({
    mutationFn: () => (saved ? api.removeFavourite(market.slug, number) : api.addFavourite(market.slug, number)),
    onSettled: () => client.invalidateQueries({ queryKey: favouritesKey(market.slug) }),
  });

  return (
    <button
      type="button"
      aria-pressed={saved}
      aria-label={saved ? t("unsave") : t("save")}
      disabled={toggle.isPending}
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!isSignedIn) {
          router.push(`${href("/login")}?next=${encodeURIComponent(window.location.pathname)}`);
          return;
        }
        toggle.mutate();
      }}
      className={clsx("inline-flex items-center gap-1.5 rounded-full bg-surface/95 p-1.5 text-ink shadow-xs hover:bg-subtle", className)}
    >
      <Heart className={clsx("size-4", saved && "fill-danger-text text-danger-text")} />
      {withLabel ? <span className="pe-1 text-body-sm font-medium">{saved ? t("saved") : t("save")}</span> : null}
    </button>
  );
}

export function AdPrice({ ad, className }: { ad: Pick<AdCardData, "price" | "is_negotiable" | "kind">; className?: string }) {
  const t = useTranslations("classifieds");
  if (!ad.price) return <p className={clsx("text-body-lg font-bold", className)}>{ad.kind === "wanted" ? t("wanted") : t("priceOnRequest")}</p>;
  return (
    <p className={clsx("flex items-baseline gap-1", className)}>
      <span className="text-caption font-medium">{ad.price.currency}</span>
      <span className="text-body-lg font-bold">{formatAmount(ad.price.amount)}</span>
    </p>
  );
}

/** Classified card (Figma Classifieds home 54:2): photo count, key specs, city, verified badge. */
export function AdCard({ ad, propertySlugs, showStatus = false }: { ad: AdCardData; propertySlugs?: string[]; showStatus?: boolean }) {
  const t = useTranslations("classifieds");
  const market = useMarket();
  const href = useHref();
  const specs = Object.values(ad.key_specs ?? {}).map((v) => api.specText(v)).filter(Boolean);
  const link = href(adPath(ad, propertySlugs ? new Set(propertySlugs) : undefined));

  return (
    <article className={clsx("relative flex h-full flex-col overflow-hidden rounded-md border bg-surface", ad.is_featured ? "border-brand" : "border-line")}>
      <Link href={link} className="flex flex-1 flex-col">
        <div className="relative aspect-[4/3] w-full overflow-hidden bg-subtle">
          <AdImage src={ad.image} alt={ad.title} />
          {ad.is_featured ? <span className="absolute start-2 top-2 rounded-xs bg-brand px-1.5 py-0.5 text-caption font-semibold">{t("featured")}</span> : null}
          <span className="absolute bottom-2 start-2 flex items-center gap-2 rounded-xs bg-ink/70 px-1.5 py-0.5 text-caption text-white">
            <Camera className="size-3" /> {ad.image_count}
            {ad.has_video ? <Video className="size-3" /> : null}
          </span>
        </div>
        <div className="flex flex-1 flex-col gap-1 p-2.5">
          <AdPrice ad={ad} />
          <h3 className="line-clamp-2 text-body font-medium">{ad.title}</h3>
          <AdFacts facts={ad.property} />
          {specs.length > 0 && !ad.property ? <p className="line-clamp-1 text-caption text-secondary">{specs.join(" · ")}</p> : null}
          <p className="mt-auto flex items-center gap-1 pt-1 text-caption text-muted">
            <MapPin className="size-3" />
            <span className="truncate">{[ad.city, ad.area].filter(Boolean).join(" · ") || "—"}</span>
            {ad.posted_at ? <span className="ms-auto shrink-0">{formatDay(ad.posted_at, market.locale)}</span> : null}
          </p>
          <div className="flex flex-wrap items-center gap-1.5">
            {ad.verified_business ? (
              <Badge tone="info">
                <BadgeCheck className="size-3" /> {t("verifiedBusiness")}
              </Badge>
            ) : null}
            {ad.kind === "wanted" ? <Badge tone="warning">{t("wanted")}</Badge> : null}
            {showStatus ? <AdStatusChip status={ad.status} /> : null}
          </div>
        </div>
      </Link>
      {ad.status === "active" ? <FavouriteButton number={ad.number} className="absolute end-2 top-2" /> : null}
    </article>
  );
}

/** Property facts on cards (round 2): "3 beds · 2 baths · 120 m²". */
export function AdFacts({ facts }: { facts: AdCardData["property"] }) {
  const t = useTranslations("realEstate");
  if (!facts) return null;
  const has = (v: unknown) => v !== null && v !== undefined && v !== "";
  const parts = [
    has(facts.bedrooms) ? (Number(facts.bedrooms) === 0 ? t("studio") : t("facts.beds", { count: Number(facts.bedrooms) })) : null,
    has(facts.bathrooms) ? t("facts.baths", { count: Number(facts.bathrooms) }) : null,
    has(facts.area_sqm) ? t("facts.area", { value: String(Number(facts.area_sqm)) }) : null,
  ].filter(Boolean);
  if (parts.length === 0) return null;
  return <p className="line-clamp-1 text-caption text-secondary">{parts.join(" · ")}</p>;
}

const STATUS_TONE: Record<string, "success" | "warning" | "danger" | "info" | "neutral"> = {
  active: "success",
  pending_review: "warning",
  pending_contract: "warning",
  draft: "neutral",
  paused: "info",
  sold: "info",
  expired: "danger",
  rejected: "danger",
  archived: "neutral",
  open: "success",
  closed: "neutral",
};

export function AdStatusChip({ status }: { status: string }) {
  const t = useTranslations("classifieds.status");
  const key = status as "active";
  return <Badge tone={STATUS_TONE[status] ?? "neutral"}>{t.has(key) ? t(key) : status}</Badge>;
}

export function AdGrid({ ads, propertySlugs, showStatus }: { ads: AdCardData[]; propertySlugs?: string[]; showStatus?: boolean }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      {ads.map((ad) => (
        <AdCard key={ad.number} ad={ad} propertySlugs={propertySlugs} showStatus={showStatus} />
      ))}
    </div>
  );
}
