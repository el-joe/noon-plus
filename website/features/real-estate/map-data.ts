import type { Money } from "@/lib/api/schemas";

/*
 * Real-estate map view (FR-CLS-5, D16 leaflet): pure helpers shared by the
 * server page and the client map. Kept free of leaflet / React so they run in
 * node tests and on the server.
 */

/** One pin on the map. `image` / `place` come from the listing card when the ad is on the loaded page. */
export type MapListing = {
  number: string;
  title: string;
  price: Money | null;
  lat: number;
  lng: number;
  featured: boolean;
  image?: string | null;
  place?: string | null;
};

/** [[south, west], [north, east]] — leaflet's LatLngBoundsLiteral. */
export type MapBounds = [[number, number], [number, number]];

export type TileConfig = { url: string; attribution: string; maxZoom: number };

export const DEFAULT_TILE_URL = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
export const DEFAULT_TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
export const DEFAULT_TILE_MAX_ZOOM = 19;

/** True for a finite WGS84 coordinate pair (0,0 "null island" is treated as missing data). */
export function isValidLatLng(lat: unknown, lng: unknown): lat is number {
  if (typeof lat !== "number" || typeof lng !== "number") return false;
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return false;
  return !(lat === 0 && lng === 0);
}

type CardLike = {
  number: string;
  title: string;
  price: Money | null;
  is_featured?: boolean;
  image?: string | null;
  city?: string | null;
  area?: string | null;
  location: { lat: number; lng: number } | null;
};

const placeOf = (card: Pick<CardLike, "area" | "city">) => [card.area, card.city].filter(Boolean).join(", ") || null;

/** Pins from listing cards (`ClassifiedPresenter::card`): only cards with a usable location. */
export function listingsFromCards(cards: readonly CardLike[]): MapListing[] {
  const out: MapListing[] = [];
  for (const card of cards) {
    const loc = card.location;
    if (!loc || !isValidLatLng(loc.lat, loc.lng)) continue;
    out.push({ number: card.number, title: card.title, price: card.price, lat: loc.lat, lng: loc.lng, featured: !!card.is_featured, image: card.image ?? null, place: placeOf(card) });
  }
  return out;
}

/** `GET classifieds/map` (ClassifiedController@map): a GeoJSON FeatureCollection of points. */
export type MapFeatureCollection = {
  type: "FeatureCollection";
  features: {
    type: "Feature";
    geometry: { type: string; coordinates: unknown };
    properties: { number: string; title: string; price: string | null; currency: string | null; featured?: boolean };
  }[];
};

/**
 * Pins from the GeoJSON map endpoint (all filtered ads with a location, up to
 * the API's cap), enriched with the photo / place of the matching card when it
 * is on the loaded page. GeoJSON coordinates are [lng, lat].
 */
export function listingsFromGeoJson(collection: MapFeatureCollection | null | undefined, cards: readonly CardLike[] = []): MapListing[] {
  if (!collection || !Array.isArray(collection.features)) return [];
  const byNumber = new Map(cards.map((c) => [c.number, c]));
  const seen = new Set<string>();
  const out: MapListing[] = [];
  for (const feature of collection.features) {
    if (feature?.geometry?.type !== "Point" || !Array.isArray(feature.geometry.coordinates)) continue;
    const [lng, lat] = feature.geometry.coordinates as unknown[];
    const p = feature.properties;
    if (!p?.number || seen.has(p.number) || !isValidLatLng(lat, lng)) continue;
    seen.add(p.number);
    const card = byNumber.get(p.number);
    out.push({
      number: p.number,
      title: p.title,
      price: p.price !== null && p.price !== undefined && p.currency ? { amount: String(p.price), currency: p.currency } : (card?.price ?? null),
      lat: lat as number,
      lng: lng as number,
      featured: !!p.featured,
      image: card?.image ?? null,
      place: card ? placeOf(card) : null,
    });
  }
  return out;
}

/** Bounds that contain every pin, or null when there is none. A single pin gives a zero-size box (the map pads / caps the zoom). */
export function boundsOf(listings: readonly Pick<MapListing, "lat" | "lng">[]): MapBounds | null {
  if (listings.length === 0) return null;
  let south = Infinity;
  let west = Infinity;
  let north = -Infinity;
  let east = -Infinity;
  for (const { lat, lng } of listings) {
    south = Math.min(south, lat);
    north = Math.max(north, lat);
    west = Math.min(west, lng);
    east = Math.max(east, lng);
  }
  return [
    [south, west],
    [north, east],
  ];
}

/** Short price for a pin: "AED 1.2M", "OMR 950", "AED 85K". Null without a price. */
export function pinLabel(price: Money | null | undefined): string | null {
  if (!price) return null;
  const n = Number(price.amount);
  if (!Number.isFinite(n)) return null;
  const compact = (value: number, suffix: string) => `${Number(value.toFixed(value < 10 ? 1 : 0))}${suffix}`;
  const text = n >= 1_000_000 ? compact(n / 1_000_000, "M") : n >= 10_000 ? compact(n / 1_000, "K") : String(Math.round(n));
  return `${price.currency} ${text}`;
}

/** Escape text for the DivIcon's HTML string. */
export function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[ch]!);
}

/**
 * Tile layer from the build-time env (NEXT_PUBLIC_MAP_TILE_URL /
 * _ATTRIBUTION / _MAX_ZOOM), defaulting to the public OpenStreetMap tiles so
 * a commercial tile provider key can be dropped in later without code.
 */
export function tileConfig(env: { url?: string | null; attribution?: string | null; maxZoom?: string | null }): TileConfig {
  const url = env.url?.trim() || DEFAULT_TILE_URL;
  // A custom provider must name its own attribution; the OSM text only belongs to the default tiles.
  const attribution = env.attribution?.trim() || (url === DEFAULT_TILE_URL ? DEFAULT_TILE_ATTRIBUTION : "");
  const zoom = Number(env.maxZoom);
  const maxZoom = env.maxZoom && Number.isInteger(zoom) && zoom >= 1 && zoom <= 22 ? zoom : DEFAULT_TILE_MAX_ZOOM;
  return { url, attribution, maxZoom };
}

/** The tile config this build was made with (literal `process.env.NEXT_PUBLIC_*` reads so Next inlines them). */
export function envTileConfig(): TileConfig {
  return tileConfig({
    url: process.env.NEXT_PUBLIC_MAP_TILE_URL,
    attribution: process.env.NEXT_PUBLIC_MAP_TILE_ATTRIBUTION,
    maxZoom: process.env.NEXT_PUBLIC_MAP_TILE_MAX_ZOOM,
  });
}
