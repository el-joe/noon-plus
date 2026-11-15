import { Building2, Car, Plane, Users } from "lucide-react";
import Link from "next/link";
import { Rail } from "@/components/ui/carousel";
import { getT } from "@/lib/i18n";
import { items, str, type BlockContext, type Settings } from "../settings";
import { BlockSection, ImageLink } from "./shared";

export const VERTICALS = [
  { key: "classifieds", path: "/classified", icon: Car },
  { key: "travel", path: "/travel", icon: Plane },
  { key: "realEstate", path: "/property", icon: Building2 },
  { key: "creators", path: "/creators", icon: Users },
] as const;

export type VerticalKey = (typeof VERTICALS)[number]["key"];

/** Fallback teaser for a vertical carousel: editor tiles, or a link into the vertical. */
export function VerticalTeaser({ s, ctx, vertical: key }: { s: Settings; ctx: BlockContext; vertical: VerticalKey }) {
  const vertical = VERTICALS.find((v) => v.key === key) ?? VERTICALS[0];
  const t = getT(ctx.market.locale);
  const tiles = items(s, "items", ctx.market.locale);
  const href = `/${ctx.market.slug}${vertical.path}`;
  return (
    <BlockSection s={{ view_all: vertical.path, ...s }} ctx={ctx} title={str(s, "title", ctx.market.locale) ?? t(`blocks.${vertical.key}`)}>
      {tiles.length > 0 ? (
        <Rail itemClass="basis-[60%] sm:basis-1/3 lg:basis-1/5">
          {tiles.map((tile, i) => (
            <div key={i} className="flex flex-col gap-1.5">
              <ImageLink item={tile} ctx={ctx} className="aspect-[4/3] w-full" />
              {tile.label ? <span className="text-body font-medium">{tile.label}</span> : null}
              {tile.caption ? <span className="text-body-sm text-secondary">{tile.caption}</span> : null}
            </div>
          ))}
        </Rail>
      ) : (
        <Link href={href} className="flex items-center gap-3 rounded-lg border border-line bg-surface p-4 text-body font-medium hover:shadow-sm">
          <vertical.icon className="size-5" />
          {t(`blocks.${vertical.key}`)} ›
        </Link>
      )}
    </BlockSection>
  );
}

