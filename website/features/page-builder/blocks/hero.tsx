import { Rail } from "@/components/ui/carousel";
import { item, items, list, num, str, type BlockContext, type Settings } from "../settings";
import { BlockSection, ImageLink, StyledCard } from "./shared";

/** 1 hero_carousel — large slides, autoplay, dots. */
export function HeroCarousel({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const slides = items(s, "slides", ctx.market.locale).filter((x) => x.image || x.label);
  if (slides.length === 0) return null;
  const autoplay = num(s, "autoplay", 5);
  return (
    <Rail itemClass="basis-full" gap="gap-0" loop={slides.length > 1} dots autoplayMs={autoplay > 0 && slides.length > 1 ? autoplay * 1000 : undefined}>
      {slides.map((slide, i) => (
        <ImageLink key={i} item={slide} ctx={ctx} className="aspect-[750/600] w-full md:aspect-[909/300]" />
      ))}
    </Rail>
  );
}

/**
 * 2 hero_bento — main carousel + stacked side slots; `variant: "cards"` is the
 * home design's row of styled cards: each slot is a card
 * ({title, subtitle, sticker, code, image, link, tone}) or `{items: [card, card]}`
 * stacked in one column.
 */
export function HeroBento({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  if (str(s, "variant") === "cards") return <HeroCards s={s} ctx={ctx} />;
  const slots = list(s, "slots").map((slot) => {
    const slides = items(slot, "slides", ctx.market.locale);
    return slides.length > 0 ? slides : [item(slot, ctx.market.locale)];
  });
  if (slots.length === 0) return null;
  const [main, ...side] = slots;
  return (
    <div className="grid gap-3 md:grid-cols-3">
      <div className="md:col-span-2">
        <Rail itemClass="basis-full" gap="gap-0" loop={main.length > 1} dots autoplayMs={main.length > 1 ? 5000 : undefined}>
          {main.map((slide, i) => (
            <ImageLink key={i} item={slide} ctx={ctx} className="aspect-[2/1] w-full" />
          ))}
        </Rail>
      </div>
      <div className="grid gap-3">
        {side.slice(0, 2).map((slot, i) => (
          <ImageLink key={i} item={slot[0]} ctx={ctx} className="aspect-[2/1] w-full md:aspect-auto md:h-full" />
        ))}
      </div>
    </div>
  );
}

function HeroCards({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const slots = list(s, "slots");
  if (slots.length === 0) return null;
  return (
    <Rail itemClass="basis-[82%] sm:basis-1/2 lg:basis-1/4">
      {slots.map((slot, i) => {
        const stack = list(slot, "items");
        return stack.length > 0 ? (
          <div key={i} className="flex h-full min-h-80 flex-col gap-3">
            {stack.slice(0, 3).map((card, j) => (
              <StyledCard key={j} s={card} ctx={ctx} size="compact" className="flex-1" />
            ))}
          </div>
        ) : (
          <StyledCard key={i} s={slot} ctx={ctx} size="hero" />
        );
      })}
    </Rail>
  );
}

const HEIGHTS: Record<string, string> = { thin: "h-[110px]", half: "h-[180px] md:h-[240px]", full: "h-[200px] md:h-[290px]" };

/** 3 banner_slider — thin/half/full height strip slider. */
export function BannerSlider({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const slides = items(s, "slides", ctx.market.locale);
  if (slides.length === 0) return null;
  const height = HEIGHTS[str(s, "height") ?? "full"] ?? HEIGHTS.full;
  const half = str(s, "width") === "half";
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass={half ? "basis-full md:basis-1/2" : "basis-full"} loop={slides.length > 1} dots autoplayMs={slides.length > 1 ? 6000 : undefined}>
        {slides.map((slide, i) => (
          <ImageLink key={i} item={slide} ctx={ctx} className={`w-full ${height}`} />
        ))}
      </Rail>
    </BlockSection>
  );
}

/** 4 banner_scroller — 2–4 banners visible with arrows. */
export function BannerScroller({ s, ctx }: { s: Settings; ctx: BlockContext }) {
  const banners = items(s, "items", ctx.market.locale);
  if (banners.length === 0) return null;
  const visible = Math.min(4, Math.max(2, num(s, "visible", 3)));
  const basis = { 2: "md:basis-1/2", 3: "md:basis-1/3", 4: "md:basis-1/4" }[visible as 2 | 3 | 4];
  return (
    <BlockSection s={s} ctx={ctx}>
      <Rail itemClass={`basis-[85%] ${basis}`}>
        {banners.map((banner, i) => (
          <ImageLink key={i} item={banner} ctx={ctx} className="aspect-[16/9] w-full" />
        ))}
      </Rail>
    </BlockSection>
  );
}
