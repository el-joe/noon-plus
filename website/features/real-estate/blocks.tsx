import { Rail } from "@/components/ui/carousel";
import { BlockSection } from "@/features/page-builder/blocks/shared";
import { VerticalTeaser } from "@/features/page-builder/blocks/vertical-teaser";
import { num, str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import { getClassifiedCategories, getProperties, propertyCategories, PURPOSES, type PropertyFilters } from "@/lib/api/real-estate";
import { getT } from "@/lib/i18n";
import { PropertyCardView } from "./shared";

/**
 * 37 properties_carousel — live property ads (featured first, the classifieds
 * search's "recommended" order). Settings: `source` / `category` (a property
 * category slug) or a purpose (rent, sale, off_plan), `city`, `limit`.
 */
export async function PropertiesCarousel({ s, ctx }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  const source = str(s, "category") ?? str(s, "source");
  const roots = await getClassifiedCategories(ctx.market.slug)
    .then((r) => propertyCategories(r.data))
    .catch(() => []);
  const all = roots.flatMap(function flat(c): typeof roots {
    return [c, ...c.children.flatMap(flat)];
  });
  const category = all.find((c) => c.slug === source) ?? roots[0];
  if (!category) return <VerticalTeaser s={s} ctx={ctx} vertical="realEstate" />;
  const filters: PropertyFilters = { category: category.slug, per_page: num(s, "limit", 12) };
  const purpose = str(s, "purpose") ?? source;
  if (purpose && (PURPOSES as readonly string[]).includes(purpose)) filters.purpose = purpose;
  const city = str(s, "city");
  if (city) filters.city = city;
  const ads = await getProperties(ctx.market.slug, filters)
    .then((r) => r.data)
    .catch(() => []);
  if (ads.length === 0) return <VerticalTeaser s={s} ctx={ctx} vertical="realEstate" />;
  const viewAll = `/property?category=${encodeURIComponent(category.slug)}${filters.purpose ? `&purpose=${filters.purpose}` : ""}`;
  return (
    <BlockSection s={{ view_all: viewAll, ...s }} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t("blocks.realEstate")}>
      <Rail itemClass="basis-[75%] sm:basis-1/2 lg:basis-1/4">
        {ads.map((ad) => (
          <PropertyCardView key={ad.number} ad={ad} market={ctx.market} />
        ))}
      </Rail>
    </BlockSection>
  );
}
