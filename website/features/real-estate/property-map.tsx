"use client";

import { MapPinOff } from "lucide-react";
import dynamic from "next/dynamic";
import { useTranslations } from "next-intl";
import { Skeleton } from "@/components/ui/misc";
import type { MapListing } from "./map-data";

/** D16: leaflet (and its CSS) load only in the browser, only on the map view. */
const PropertyMapLeaflet = dynamic(() => import("./property-map-leaflet"), {
  ssr: false,
  loading: () => <Skeleton className="size-full rounded-none" />,
});

/**
 * Real-estate map view (FR-CLS-5): price pins for every listing with a
 * location, fitted to the pins; an empty state when none has coordinates.
 */
export function PropertyMap({ listings, listingBase, total, capped }: { listings: MapListing[]; listingBase: string; total: number; capped?: boolean }) {
  const t = useTranslations("realEstate.map");
  if (listings.length === 0) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-lg border border-line bg-surface px-6 py-12 text-center" role="status">
        <MapPinOff className="size-10 text-muted" aria-hidden />
        <h2 className="text-h4 font-semibold">{t("emptyTitle")}</h2>
        <p className="max-w-md text-body text-secondary">{t("emptyBody")}</p>
      </div>
    );
  }
  const missing = Math.max(0, total - listings.length);
  return (
    <div className="flex flex-col gap-2">
      <p className="text-body-sm text-secondary" aria-live="polite">
        {t("showing", { count: listings.length })}
        {capped ? ` ${t("capped")}` : missing > 0 ? ` ${t("withoutLocation", { count: missing })}` : ""}
      </p>
      <div className="relative z-0 h-[65vh] min-h-[420px] overflow-hidden rounded-lg border border-line">
        <PropertyMapLeaflet listings={listings} listingBase={listingBase} />
      </div>
      <p className="text-caption text-secondary">{t("keyboardHint")}</p>
    </div>
  );
}
