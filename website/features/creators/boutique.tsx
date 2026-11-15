import { BadgeCheck, ExternalLink, ShoppingBag } from "lucide-react";
import { notFound } from "next/navigation";
import { EmptyState } from "@/components/ui/misc";
import { ProductGrid } from "@/components/product/product-grid";
import { FollowButton } from "@/features/classifieds/follow-button";
import { Crumbs } from "@/features/content/pagination";
import { orNull } from "@/lib/api/client";
import { getCreator, type CreatorDetail } from "@/lib/api/creators";
import { cardFromApi } from "@/lib/api/mappers";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { initials, mediaUrl, typeKey } from "./creator-card";

export async function loadCreator(market: Market, slug: string): Promise<CreatorDetail | null> {
  return orNull(getCreator(market.slug, slug).then((r) => r.data));
}

/**
 * Creator boutique (Figma 59:3228): profile header, bio, socials and picks
 * (the listings of the creator's live campaigns) as product cards.
 */
export async function CreatorBoutique({ market, slug }: { market: Market; slug: string }) {
  const t = getT(market.locale);
  const creator = await loadCreator(market, slug);
  if (!creator) notFound();
  const avatar = mediaUrl(creator.avatar_url);
  const cover = mediaUrl(creator.cover_url);
  const types = (creator.job_types ?? []).map(typeKey).filter((k): k is NonNullable<ReturnType<typeof typeKey>> => !!k);
  const socials = Array.isArray(creator.socials) ? [] : Object.entries(creator.socials).filter((e): e is [string, string] => typeof e[1] === "string" && /^https?:\/\//.test(e[1]));
  const products = creator.picks.map((pick) => cardFromApi({ ...pick, slug: pick.product_slug ?? pick.slug }));

  return (
    <div className="flex flex-col gap-6">
      <Crumbs market={market} items={[{ label: t("creators.title"), href: `/${market.slug}/creators` }, { label: creator.name ?? slug }]} />
      <section className="overflow-hidden rounded-lg border border-line bg-surface">
        <div className="h-36 bg-brand-subtle md:h-48">
          {cover ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={cover} alt="" className="size-full object-cover" />
          ) : null}
        </div>
        <div className="flex flex-col gap-3 px-5 pb-5 md:flex-row md:items-end">
          <span className="-mt-10 flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-full border-4 border-surface bg-brand text-h3 font-bold">
            {avatar ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={avatar} alt="" className="size-full object-cover" />
            ) : (
              initials(creator.name)
            )}
          </span>
          <div className="min-w-0 flex-1">
            <h1 className="flex items-center gap-2 text-h2 font-bold">
              {creator.name}
              {creator.verified ? <BadgeCheck className="size-5 text-link" aria-label={t("creators.verified")} /> : null}
            </h1>
            <p className="text-body-sm text-secondary">
              {[...types.map((k) => t(`creators.type.${k}`)), t("creators.followers", { count: creator.followers ?? 0 })].join(" · ")}
            </p>
          </div>
          <FollowButton slug={creator.slug} type="marketer" />
          {socials.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              {socials.map(([network, url]) => (
                <a key={network} href={url} target="_blank" rel="noopener noreferrer nofollow" className="inline-flex h-8 items-center gap-1 rounded-md border border-line-strong px-3 text-label font-semibold capitalize">
                  {network}
                  <ExternalLink className="size-3.5" />
                </a>
              ))}
            </div>
          ) : null}
        </div>
        {creator.bio ? (
          <div className="border-t border-line px-5 py-4">
            <h2 className="mb-1 text-label font-semibold uppercase text-secondary">{t("creators.about")}</h2>
            <p className="whitespace-pre-line text-body">{creator.bio}</p>
          </div>
        ) : null}
      </section>

      <section>
        <h2 className="mb-3 text-h3 font-semibold">{t("creators.picks", { name: creator.name ?? "" })}</h2>
        {products.length === 0 ? (
          <EmptyState icon={<ShoppingBag className="size-10" />} title={t("creators.noPicks")} />
        ) : (
          <ProductGrid products={products} columns={5} />
        )}
      </section>
    </div>
  );
}
