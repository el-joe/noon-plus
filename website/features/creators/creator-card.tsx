import { BadgeCheck } from "lucide-react";
import Link from "next/link";
import type { CreatorCard as Creator } from "@/lib/api/creators";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";

const compact = (n: number, locale: string) => new Intl.NumberFormat(locale === "ar" ? "ar-u-nu-latn" : "en", { notation: "compact", maximumFractionDigits: 1 }).format(n);

export function initials(name: string | null | undefined): string {
  return (name ?? "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("");
}

/** Only absolute http(s) or root-relative media paths are rendered. */
export function mediaUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  return /^https?:\/\//.test(path) || path.startsWith("/") ? path : null;
}

export function typeKey(type: string): "influencer" | "affiliate" | "broker" | null {
  return type === "influencer" || type === "affiliate" || type === "broker" ? type : null;
}

/** Creator card (Figma Creators directory 57:2752). */
export function CreatorCardView({ market, creator }: { market: Market; creator: Creator }) {
  const t = getT(market.locale);
  const avatar = mediaUrl(creator.avatar_url);
  const cover = mediaUrl(creator.cover_url);
  const types = (creator.job_types ?? []).map(typeKey).filter((k): k is NonNullable<ReturnType<typeof typeKey>> => !!k);
  return (
    <Link href={`/${market.slug}/creators/${encodeURIComponent(creator.slug)}`} className="group flex flex-col overflow-hidden rounded-lg border border-line bg-surface hover:shadow-sm">
      <div className="h-20 bg-brand-subtle">
        {cover ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={cover} alt="" loading="lazy" className="size-full object-cover" />
        ) : null}
      </div>
      <div className="-mt-7 flex flex-1 flex-col items-center gap-1 px-3 pb-3 text-center">
        <span className="flex size-14 items-center justify-center overflow-hidden rounded-full border-2 border-surface bg-brand text-body font-semibold">
          {avatar ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={avatar} alt="" className="size-full object-cover" />
          ) : (
            initials(creator.name)
          )}
        </span>
        <span className="flex items-center gap-1 text-body font-semibold">
          <span className="line-clamp-1">{creator.name}</span>
          {creator.verified ? <BadgeCheck className="size-4 shrink-0 text-link" aria-label={t("creators.verified")} /> : null}
        </span>
        {types.length > 0 ? <span className="text-caption text-secondary">{types.map((k) => t(`creators.type.${k}`)).join(" · ")}</span> : null}
        <span className="text-caption text-secondary">
          <strong className="text-ink">{compact(creator.followers ?? 0, market.locale)}</strong> {t("creators.followersLabel")}
        </span>
        <span className="mt-2 w-full rounded-md border border-line-strong py-1.5 text-label font-semibold group-hover:bg-subtle">{t("creators.visitBoutique")}</span>
      </div>
    </Link>
  );
}
