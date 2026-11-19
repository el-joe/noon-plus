"use client";

import "leaflet/dist/leaflet.css";
import "./property-map.css";
import L from "leaflet";
import { useLocale, useTranslations } from "next-intl";
import Link from "next/link";
import { useEffect, useMemo } from "react";
import { MapContainer, Marker, Popup, TileLayer, useMap, ZoomControl } from "react-leaflet";
import { formatMoney } from "@/lib/format";
import { boundsOf, envTileConfig, escapeHtml, pinLabel, type MapListing } from "./map-data";

export type PropertyMapProps = {
  listings: MapListing[];
  /** `/{market}/property`: a pin's popup links to `{listingBase}/{number}`. */
  listingBase: string;
};

/** Price pin (a DivIcon: leaflet's default PNG icons do not resolve under the bundler). */
function pinIcon(listing: MapListing, fallback: string): L.DivIcon {
  const label = escapeHtml(pinLabel(listing.price) ?? fallback);
  return L.divIcon({
    className: "nawy-map-pin",
    html: `<span class="nawy-map-pin__label${listing.featured ? " nawy-map-pin__label--featured" : ""}" dir="ltr">${label}</span>`,
    // Zero-size anchor box at the point; the label is centred above it in CSS.
    iconSize: [0, 0],
    iconAnchor: [0, 0],
    popupAnchor: [0, -18],
  });
}

/** Fit the view to every pin whenever the set changes. */
function FitBounds({ listings, maxZoom }: { listings: MapListing[]; maxZoom: number }) {
  const map = useMap();
  useEffect(() => {
    const bounds = boundsOf(listings);
    if (!bounds) return;
    map.fitBounds(bounds, { padding: [48, 48], maxZoom: Math.min(15, maxZoom) });
  }, [map, listings, maxZoom]);
  return null;
}

/** Localise leaflet's built-in English labels (popup close button) and the map's own name. */
function A11y({ label, closeLabel }: { label: string; closeLabel: string }) {
  const map = useMap();
  useEffect(() => {
    const container = map.getContainer();
    container.setAttribute("aria-label", label);
    container.setAttribute("role", "region");
    const onOpen = (e: L.PopupEvent) => {
      const close = e.popup.getElement()?.querySelector(".leaflet-popup-close-button");
      close?.setAttribute("aria-label", closeLabel);
      close?.setAttribute("title", closeLabel);
    };
    map.on("popupopen", onOpen);
    return () => {
      map.off("popupopen", onOpen);
    };
  }, [map, label, closeLabel]);
  return null;
}

/**
 * Leaflet map of property ads (OpenStreetMap tiles by default, D16). Loaded
 * only in the browser through `PropertyMap` (next/dynamic, ssr: false).
 *
 * The map itself is `dir="ltr"` (leaflet positions tiles / controls in LTR
 * pixel space), while popups carry the page's language direction.
 */
export default function PropertyMapLeaflet({ listings, listingBase }: PropertyMapProps) {
  const t = useTranslations("realEstate.map");
  const locale = useLocale();
  const rtl = locale === "ar";
  const tiles = useMemo(() => envTileConfig(), []);
  const icons = useMemo(() => new Map(listings.map((l) => [l.number, pinIcon(l, t("pinNoPrice"))])), [listings, t]);
  const first = listings[0];

  return (
    <div dir="ltr" className="nawy-map isolate size-full">
      <MapContainer
        center={first ? [first.lat, first.lng] : [0, 0]}
        zoom={first ? 12 : 2}
        maxZoom={tiles.maxZoom}
        zoomControl={false}
        scrollWheelZoom
        keyboard
        className="size-full"
      >
        <TileLayer url={tiles.url} attribution={tiles.attribution} maxZoom={tiles.maxZoom} />
        <ZoomControl position={rtl ? "topleft" : "topright"} zoomInTitle={t("zoomIn")} zoomOutTitle={t("zoomOut")} />
        <FitBounds listings={listings} maxZoom={tiles.maxZoom} />
        <A11y label={t("label")} closeLabel={t("closePopup")} />
        {listings.map((listing) => {
          const price = listing.price ? formatMoney(listing.price) : null;
          return (
            <Marker
              key={listing.number}
              position={[listing.lat, listing.lng]}
              icon={icons.get(listing.number)}
              title={price ? `${listing.title} · ${price}` : listing.title}
              keyboard
              riseOnHover
            >
              <Popup minWidth={220} maxWidth={260}>
                <div dir={rtl ? "rtl" : "ltr"} lang={locale} className="flex flex-col gap-2">
                  {listing.image && /^(https?:)?\//.test(listing.image) ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={listing.image} alt="" loading="lazy" className="aspect-[4/3] w-full rounded-md object-cover" />
                  ) : null}
                  {listing.featured ? <span className="self-start rounded-xs bg-brand px-1.5 py-0.5 text-caption font-medium text-ink">{t("featured")}</span> : null}
                  {price ? (
                    <p className="m-0 text-body font-bold" dir="ltr">
                      {price}
                    </p>
                  ) : null}
                  <p className="m-0 line-clamp-2 text-body-sm font-medium">{listing.title}</p>
                  {listing.place ? <p className="m-0 text-caption text-secondary">{listing.place}</p> : null}
                  <Link href={`${listingBase}/${encodeURIComponent(listing.number)}`} className="nawy-map-link text-body-sm font-semibold hover:underline">
                    {t("viewListing")}
                  </Link>
                </div>
              </Popup>
            </Marker>
          );
        })}
      </MapContainer>
    </div>
  );
}
