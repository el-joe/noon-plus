import { Rail } from "@/components/ui/carousel";
import { BlockSection } from "@/features/page-builder/blocks/shared";
import { VerticalTeaser } from "@/features/page-builder/blocks/vertical-teaser";
import { items, num, str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import * as api from "@/lib/api/classifieds";
import { getT } from "@/lib/i18n";
import { AdCard } from "./ad-card";
import { loadClassifiedCategories } from "./data";

/**
 * 35 classified_carousel — live Open Souq ads (settings: `category`,
 * `kind`, `sort`, `limit`). Editor tiles, or no live ads, fall back to the
 * shared vertical teaser.
 */
export async function ClassifiedCarousel({ s, ctx }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  if (items(s, "items", ctx.market.locale).length > 0) return <VerticalTeaser s={s} ctx={ctx} vertical="classifieds" />;
  const category = str(s, "category") ?? str(s, "category_slug") ?? undefined;
  const [ads, { propertySlugs }] = await Promise.all([
    api
      .getAds(ctx.market.slug, { category, kind: str(s, "kind") ?? undefined, sort: str(s, "sort") ?? "recommended", per_page: Math.min(num(s, "limit", 12), 24) })
      .then((r) => r.data)
      .catch(() => []),
    loadClassifiedCategories(ctx.market.slug),
  ]);
  if (ads.length === 0) return <VerticalTeaser s={s} ctx={ctx} vertical="classifieds" />;
  const t = getT(ctx.market.locale);
  const viewAll = `/classified/search${category ? `?category=${encodeURIComponent(category)}` : ""}`;

  return (
    <BlockSection s={{ view_all: viewAll, ...s }} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t("blocks.classifieds")}>
      <Rail itemClass="basis-[60%] sm:basis-1/3 lg:basis-1/5">
        {ads.map((ad) => (
          <AdCard key={ad.number} ad={ad} propertySlugs={propertySlugs} />
        ))}
      </Rail>
    </BlockSection>
  );
}
