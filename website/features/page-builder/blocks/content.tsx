import { Radio, Smartphone } from "lucide-react";
import Link from "next/link";
import { Rail } from "@/components/ui/carousel";
import { Badge } from "@/components/ui/misc";
import { getT } from "@/lib/i18n";
import { sanitizeHtml } from "@/lib/sanitize";
import { items, resolveLink, str, strings, type BlockContext, type Settings } from "../settings";
import { BlockSection, ImageLink } from "./shared";
import { VERTICALS } from "./vertical-teaser";

/**
 * Group H. The vertical carousels (classifieds, travel, property, creators)
 * are implemented in each vertical's `features/<vertical>/blocks.tsx`; the
 * shared fallback teaser is `vertical-teaser.tsx`.
 */


/** 33 verticals */
export function Verticals({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {VERTICALS.map(({ key, path, icon: Icon }) => (
          <Link key={key} href={`/${ctx.market.slug}${path}`} className="flex items-center gap-3 rounded-lg border border-line bg-surface p-4 hover:shadow-sm">
            <span className="flex size-10 items-center justify-center rounded-md bg-brand-subtle text-brand-text">
              <Icon className="size-5" />
            </span>
            <span className="text-body font-semibold">{t(`blocks.${key}`)}</span>
          </Link>
        ))}
      </div>
    </BlockSection>
  );
}

/** 34–37 vertical carousels — data-driven cards live with each vertical. */
export { CreatorsCarousel } from "@/features/creators/blocks";
export { ClassifiedCarousel } from "@/features/classifieds/blocks";
export { TravelPackagesCarousel } from "@/features/travel/blocks";
export { PropertiesCarousel } from "@/features/real-estate/blocks";

/** 38 video_block — organic video from the admin media library (ADR 11). */
export function VideoBlock({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const url = str(s, "video_url");
  const slug = str(s, "stream_slug");
  const poster = str(s, "poster") ?? str(s, "image");
  if (url && /^https:\/\//.test(url)) {
    return (
      <BlockSection s={s} ctx={ctx}>
        <video src={url} poster={poster && /^https?:\/\//.test(poster) ? poster : undefined} controls playsInline preload="metadata" className="aspect-video w-full rounded-lg bg-ink" />
      </BlockSection>
    );
  }
  if (slug) {
    return (
      <BlockSection s={s} ctx={ctx}>
        <Link href={`/${ctx.market.slug}/live/${encodeURIComponent(slug)}`} className="flex aspect-video w-full items-center justify-center rounded-lg bg-ink text-white">
          <Radio className="size-10" />
        </Link>
      </BlockSection>
    );
  }
  return null;
}

type Stream = { slug: string; title: string; status: string; type: string; scheduled_at: string | null };

/** 39 live_streams_strip — admin-made live / scheduled streams (data from BlockResolver). */
export function LiveStreamsStrip({ s, ctx, data }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  const streams = Array.isArray(data?.streams) ? (data.streams as Stream[]) : [];
  if (streams.length === 0) return null;
  return (
    <BlockSection s={{ view_all: "/live", ...s }} ctx={ctx}>
      <Rail itemClass="basis-[45%] sm:basis-1/4 lg:basis-1/6">
        {streams.map((stream) => (
          <Link key={stream.slug} href={`/${ctx.market.slug}/live/${encodeURIComponent(stream.slug)}`} className="flex aspect-[9/14] flex-col justify-between rounded-lg bg-ink p-3 text-white">
            <Badge tone={stream.status === "live" ? "danger" : "neutral"} className="self-start">
              {stream.status === "live" ? t("blocks.live") : t("blocks.scheduled")}
            </Badge>
            <span className="line-clamp-2 text-body font-semibold">{stream.title}</span>
          </Link>
        ))}
      </Rail>
    </BlockSection>
  );
}

/** 40 html — sanitised rich HTML. */
export function HtmlBlock({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const html = str(s, "html", ctx.market.locale);
  if (!html) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <div className="prose-cms text-body" dangerouslySetInnerHTML={{ __html: sanitizeHtml(html) }} />
    </BlockSection>
  );
}

/** 41 seo_text — collapsible SEO copy. */
export function SeoText({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const text = str(s, "text", ctx.market.locale);
  if (!text) return null;
  const t = getT(ctx.market.locale);
  const title = str(s, "title", ctx.market.locale);
  return (
    <details className="group rounded-lg bg-surface p-4">
      <summary className="cursor-pointer list-none text-body font-semibold">
        {title ?? t("common.showMore")}
        <span className="ms-2 text-link group-open:hidden">{t("common.showMore")}</span>
      </summary>
      <div className="prose-cms mt-2 text-body-sm text-secondary" dangerouslySetInnerHTML={{ __html: sanitizeHtml(text) }} />
    </details>
  );
}

/** 42 popular_searches — keyword chips into search. */
export function PopularSearches({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const keywords = strings(s, `keywords_${ctx.market.locale}`).length ? strings(s, `keywords_${ctx.market.locale}`) : strings(s, "keywords");
  if (keywords.length === 0) return null;
  const t = getT(ctx.market.locale);
  return (
    <BlockSection s={s} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t("blocks.popularSearches")}>
      <div className="flex flex-wrap gap-2">
        {keywords.map((keyword) => (
          <Link key={keyword} href={`/${ctx.market.slug}/search?q=${encodeURIComponent(keyword)}`} className="rounded-sm border border-line bg-surface px-2.5 py-1 text-body-sm hover:bg-subtle">
            {keyword}
          </Link>
        ))}
      </div>
    </BlockSection>
  );
}

/** 43 app_download — store badges. */
export function AppDownload({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const t = getT(ctx.market.locale);
  const ios = resolveLink(str(s, "ios_url") ?? str(s, "app_store_url"), ctx.market);
  const android = resolveLink(str(s, "android_url") ?? str(s, "play_store_url"), ctx.market);
  return (
    <section className="flex flex-wrap items-center gap-4 rounded-lg bg-ink p-5 text-white">
      <Smartphone className="size-8 text-brand" />
      <div className="flex-1">
        <p className="text-h4 font-semibold">{str(s, "title", ctx.market.locale) ?? t("blocks.getApp")}</p>
        <p className="text-body-sm opacity-80">{str(s, "subtitle", ctx.market.locale) ?? t("blocks.getAppHint")}</p>
      </div>
      {ios ? <a href={ios} className="rounded-md border border-white/40 px-3 py-2 text-body-sm font-semibold">App Store</a> : null}
      {android ? <a href={android} className="rounded-md border border-white/40 px-3 py-2 text-body-sm font-semibold">Google Play</a> : null}
    </section>
  );
}

/** 44 newsletter_signup — see features/newsletter. */
export { NewsletterSignup } from "@/features/newsletter/newsletter-block";

/** 45 vendor_spotlight — featured sellers (editor items). */
export function VendorSpotlight({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const sellers = items(s, "items", ctx.market.locale);
  if (sellers.length === 0) return null;
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass="basis-[45%] sm:basis-1/4 lg:basis-1/6">
        {sellers.map((seller, i) => (
          <div key={i} className="flex flex-col items-center gap-2 rounded-lg border border-line bg-surface p-3 text-center">
            <ImageLink item={{ ...seller }} ctx={ctx} className="size-16 rounded-full" imgClass="size-full object-cover" />
            <span className="text-body font-medium">{seller.label}</span>
            {seller.caption ? <span className="text-body-sm text-secondary">{seller.caption}</span> : null}
          </div>
        ))}
      </Rail>
    </BlockSection>
  );
}

