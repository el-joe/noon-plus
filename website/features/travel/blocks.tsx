import { Rail } from "@/components/ui/carousel";
import { BlockSection } from "@/features/page-builder/blocks/shared";
import { VerticalTeaser } from "@/features/page-builder/blocks/vertical-teaser";
import { num, str, type BlockContext, type Settings } from "@/features/page-builder/settings";
import { getTravelPackages, type TravelFilters } from "@/lib/api/travel";
import { getT } from "@/lib/i18n";
import { isTripType, TripCard } from "./shared";

/**
 * 36 travel_packages_carousel — live packages (TravelController@index).
 * Settings: `source` / `trip_type` (umrah, hajj, …) or `destination`, `limit`.
 * Falls back to the editor tiles / vertical link when nothing is on sale.
 */
export async function TravelPackagesCarousel({ s, ctx }: { s: Settings; ctx: BlockContext; data?: Record<string, unknown> | null }) {
  const t = getT(ctx.market.locale);
  const source = str(s, "trip_type") ?? str(s, "source");
  const filters: TravelFilters = {};
  if (isTripType(source)) filters.trip_type = source;
  const destination = str(s, "destination") ?? (source && !isTripType(source) && source !== "latest" ? source : null);
  if (destination) filters.destination = destination;
  const packages = await getTravelPackages(ctx.market.slug, filters)
    .then((r) => r.data)
    .catch(() => []);
  if (packages.length === 0) return <VerticalTeaser s={s} ctx={ctx} vertical="travel" />;
  const viewAll = filters.trip_type ? `/travel/search?trip_type=${filters.trip_type}` : "/travel";
  return (
    <BlockSection s={{ view_all: viewAll, ...s }} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t("blocks.travel")}>
      <Rail itemClass="basis-[75%] sm:basis-1/2 lg:basis-1/4">
        {packages.slice(0, num(s, "limit", 12)).map((pkg) => (
          <TripCard key={pkg.slug} pkg={pkg} market={ctx.market} />
        ))}
      </Rail>
    </BlockSection>
  );
}
