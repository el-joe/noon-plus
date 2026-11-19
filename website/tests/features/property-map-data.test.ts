import { describe, expect, it } from "vitest";
import {
  boundsOf,
  DEFAULT_TILE_ATTRIBUTION,
  DEFAULT_TILE_MAX_ZOOM,
  DEFAULT_TILE_URL,
  escapeHtml,
  isValidLatLng,
  listingsFromCards,
  listingsFromGeoJson,
  pinLabel,
  tileConfig,
  type MapFeatureCollection,
} from "@/features/real-estate/map-data";

const card = (number: string, location: { lat: number; lng: number } | null, extra: Record<string, unknown> = {}) => ({
  number,
  title: `Ad ${number}`,
  price: { amount: "1250000.00", currency: "AED" },
  is_featured: false,
  image: `https://cdn.example/${number}.jpg`,
  city: "Dubai",
  area: "Marina",
  location,
  ...extra,
});

describe("real-estate map: coordinates", () => {
  it("accepts finite WGS84 pairs and rejects missing, out-of-range and 0,0", () => {
    expect(isValidLatLng(25.2, 55.3)).toBe(true);
    expect(isValidLatLng(-33.9, -70.6)).toBe(true);
    expect(isValidLatLng(0, 0)).toBe(false);
    expect(isValidLatLng(91, 10)).toBe(false);
    expect(isValidLatLng(10, 181)).toBe(false);
    expect(isValidLatLng(Number.NaN, 10)).toBe(false);
    expect(isValidLatLng("25.2", 55.3)).toBe(false);
    expect(isValidLatLng(null, undefined)).toBe(false);
  });

  it("extracts pins only from cards that carry a usable location", () => {
    const pins = listingsFromCards([card("A", { lat: 25.08, lng: 55.14 }, { is_featured: true }), card("B", null), card("C", { lat: 0, lng: 0 })]);
    expect(pins).toEqual([
      { number: "A", title: "Ad A", price: { amount: "1250000.00", currency: "AED" }, lat: 25.08, lng: 55.14, featured: true, image: "https://cdn.example/A.jpg", place: "Marina, Dubai" },
    ]);
  });

  it("reads GeoJSON [lng, lat] points, skips bad features and duplicates, and enriches from the page cards", () => {
    const geo: MapFeatureCollection = {
      type: "FeatureCollection",
      features: [
        { type: "Feature", geometry: { type: "Point", coordinates: [55.14, 25.08] }, properties: { number: "A", title: "Marina flat", price: "95000.00", currency: "AED", featured: true } },
        { type: "Feature", geometry: { type: "Point", coordinates: [58.4, 23.6] }, properties: { number: "Z", title: "Muscat villa", price: null, currency: "OMR" } },
        { type: "Feature", geometry: { type: "Point", coordinates: [55.14, 25.08] }, properties: { number: "A", title: "dup", price: null, currency: null } },
        { type: "Feature", geometry: { type: "Polygon", coordinates: [] }, properties: { number: "P", title: "poly", price: null, currency: null } },
        { type: "Feature", geometry: { type: "Point", coordinates: [200, 25] }, properties: { number: "X", title: "bad", price: null, currency: null } },
      ],
    };
    const pins = listingsFromGeoJson(geo, [card("A", { lat: 25.08, lng: 55.14 })]);
    expect(pins.map((p) => p.number)).toEqual(["A", "Z"]);
    expect(pins[0]).toMatchObject({ lat: 25.08, lng: 55.14, price: { amount: "95000.00", currency: "AED" }, featured: true, image: "https://cdn.example/A.jpg", place: "Marina, Dubai" });
    expect(pins[1]).toMatchObject({ lat: 23.6, lng: 58.4, price: null, featured: false, image: null, place: null });
    expect(listingsFromGeoJson(null)).toEqual([]);
  });

  it("computes bounds that contain every pin", () => {
    expect(boundsOf([])).toBeNull();
    expect(boundsOf([{ lat: 25, lng: 55 }])).toEqual([
      [25, 55],
      [25, 55],
    ]);
    expect(
      boundsOf([
        { lat: 25.2, lng: 55.3 },
        { lat: 23.6, lng: 58.4 },
        { lat: 24.4, lng: 54.4 },
      ]),
    ).toEqual([
      [23.6, 54.4],
      [25.2, 58.4],
    ]);
  });
});

describe("real-estate map: pins", () => {
  it("abbreviates prices for the pin label", () => {
    expect(pinLabel({ amount: "1250000.00", currency: "AED" })).toBe("AED 1.3M");
    expect(pinLabel({ amount: "12000000", currency: "AED" })).toBe("AED 12M");
    expect(pinLabel({ amount: "85000.00", currency: "AED" })).toBe("AED 85K");
    expect(pinLabel({ amount: "950.500", currency: "OMR" })).toBe("OMR 951");
    expect(pinLabel(null)).toBeNull();
    expect(pinLabel({ amount: "n/a", currency: "AED" })).toBeNull();
  });

  it("escapes HTML for the DivIcon markup", () => {
    expect(escapeHtml(`<b>"A" & 'B'</b>`)).toBe("&lt;b&gt;&quot;A&quot; &amp; &#39;B&#39;&lt;/b&gt;");
  });
});

describe("real-estate map: tile config from env", () => {
  it("defaults to OpenStreetMap with its attribution", () => {
    expect(tileConfig({})).toEqual({ url: DEFAULT_TILE_URL, attribution: DEFAULT_TILE_ATTRIBUTION, maxZoom: DEFAULT_TILE_MAX_ZOOM });
    expect(tileConfig({ url: "  ", attribution: "", maxZoom: "" }).url).toBe(DEFAULT_TILE_URL);
  });

  it("uses a configured provider, its attribution and max zoom", () => {
    expect(tileConfig({ url: "https://tiles.example/{z}/{x}/{y}.png?key=abc", attribution: "&copy; Example", maxZoom: "20" })).toEqual({
      url: "https://tiles.example/{z}/{x}/{y}.png?key=abc",
      attribution: "&copy; Example",
      maxZoom: 20,
    });
  });

  it("never credits OSM for a custom provider and ignores an invalid max zoom", () => {
    const config = tileConfig({ url: "https://tiles.example/{z}/{x}/{y}.png", maxZoom: "99" });
    expect(config.attribution).toBe("");
    expect(config.maxZoom).toBe(DEFAULT_TILE_MAX_ZOOM);
    expect(tileConfig({ maxZoom: "abc" }).maxZoom).toBe(DEFAULT_TILE_MAX_ZOOM);
  });
});
