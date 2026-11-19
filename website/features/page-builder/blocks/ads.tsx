import Link from "next/link";
import { Suspense } from "react";
import { AdLabel } from "@/components/ui/misc";
import { getAdPlacements } from "@/lib/api/endpoints";
import type { AdPlacement } from "@/lib/api/schemas";
import { getT } from "@/lib/i18n";
import { resolveLink, safeUrl, str, type BlockContext, type Settings } from "../settings";

/**
 * Sponsored blocks (group F). Ads are never hard-coded: the editor only places
 * a slot; creatives come from active bookings (`GET ads/placements/{placement}`)
 * and are always labelled ("Sponsored" / admin label, rule 1).
 */
export async function AdPlacements({ placement, ctx, variant }: { placement: string; ctx: BlockContext; variant: "banner" | "strip" | "tile" | "video" }) {
  let ads: AdPlacement[] = [];
  try {
    ads = (await getAdPlacements(ctx.market.slug, placement)).data;
  } catch {
    ads = [];
  }
  const t = getT(ctx.market.locale);
  const visible = ads.filter((ad) => ad.image || ad.video || ad.headline);
  if (visible.length === 0) return null;
  const grid = variant === "tile" ? "grid grid-cols-2 gap-3 md:grid-cols-4" : "grid gap-3";
  return (
    <div className={grid}>
      {visible.map((ad, i) => {
        const href = resolveLink(ad.link_url, ctx.market);
        const image = safeUrl(ad.image);
        const video = ad.creative_type === "video" || ad.video ? safeUrl(ad.video ?? null) : null;
        const body = (
          <div className="relative overflow-hidden rounded-lg bg-surface">
            {video ? (
              <video
                src={video}
                muted
                playsInline
                loop
                autoPlay
                preload="metadata"
                aria-label={ad.headline ?? t("common.sponsored")}
                className={variant === "tile" ? "aspect-square w-full object-cover" : variant === "video" ? "aspect-video w-full object-cover" : "aspect-[5/1] w-full object-cover"}
              />
            ) : image ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={image} alt={ad.headline ?? ""} loading="lazy" className={variant === "tile" ? "aspect-square w-full object-cover" : "aspect-[5/1] w-full object-cover"} />
            ) : (
              <p className="p-6 text-h4 font-semibold">{ad.headline}</p>
            )}
            <AdLabel className="absolute end-2 top-2 bg-surface/90">{ad.label || t("common.sponsored")}</AdLabel>
          </div>
        );
        return href ? (
          <Link key={i} href={href} rel="sponsored">
            {body}
          </Link>
        ) : (
          <div key={i}>{body}</div>
        );
      })}
    </div>
  );
}

function Placed({ s, ctx, fallback, variant }: { s: Settings; ctx: BlockContext; fallback: string; variant: "banner" | "strip" | "tile" | "video" }) {
  const placement = str(s, "ad_slot_key") ?? str(s, "placement") ?? fallback;
  return (
    <Suspense fallback={null}>
      <AdPlacements placement={placement} ctx={ctx} variant={variant} />
    </Suspense>
  );
}

/** 27 sponsored_brand_banner */
export const SponsoredBrandBanner = ({ s, ctx }: { s: Settings; ctx: BlockContext }) => <Placed s={s} ctx={ctx} fallback="sponsored_brand_banner" variant="banner" />;
/** 28 sponsored_product */
export const SponsoredProduct = ({ s, ctx }: { s: Settings; ctx: BlockContext }) => <Placed s={s} ctx={ctx} fallback="sponsored_product" variant="tile" />;
/** 29 sponsored_brand_video — creative uploaded by admin on the booking (ADR 11). */
export const SponsoredBrandVideo = ({ s, ctx }: { s: Settings; ctx: BlockContext }) => <Placed s={s} ctx={ctx} fallback="sponsored_brand_video" variant="video" />;
/** 30 sponsored_video */
export const SponsoredVideo = ({ s, ctx }: { s: Settings; ctx: BlockContext }) => <Placed s={s} ctx={ctx} fallback="sponsored_video" variant="video" />;
/** 31 ad_slot */
export const AdSlot = ({ s, ctx }: { s: Settings; ctx: BlockContext }) => <Placed s={s} ctx={ctx} fallback="home" variant="strip" />;
