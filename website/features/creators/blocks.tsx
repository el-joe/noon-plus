import { Suspense } from "react";
import { Rail } from "@/components/ui/carousel";
import { BlockSection } from "@/features/page-builder/blocks/shared";
import { VerticalTeaser } from "@/features/page-builder/blocks/vertical-teaser";
import { num, str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import { getCreators } from "@/lib/api/creators";
import { getT } from "@/lib/i18n";
import { CreatorCardView } from "./creator-card";

/** 34 creators_carousel — active creators of the market; the editor teaser when there are none. */
export function CreatorsCarousel({ s, ctx }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  return (
    <Suspense fallback={null}>
      <CreatorsRail s={s} ctx={ctx} />
    </Suspense>
  );
}

async function CreatorsRail({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const type = str(s, "type") ?? undefined;
  const creators = await getCreators(ctx.market.slug, { type, per_page: Math.min(24, num(s, "max_items", 12)) })
    .then((r) => r.data)
    .catch(() => []);
  if (creators.length === 0) return <VerticalTeaser s={s} ctx={ctx} vertical="creators" />;
  const t = getT(ctx.market.locale);
  return (
    <BlockSection s={{ view_all: "/creators", ...s }} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t("blocks.creators")}>
      <Rail itemClass="basis-[45%] sm:basis-1/4 lg:basis-1/6">
        {creators.map((creator) => (
          <CreatorCardView key={creator.slug} market={ctx.market} creator={creator} />
        ))}
      </Rail>
    </BlockSection>
  );
}
