import { screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { MapListing } from "@/features/real-estate/map-data";
import { renderWithProviders } from "../helpers/render";

/*
 * react-leaflet needs a real layout engine; the mock renders each layer as
 * plain DOM so the map's composition (pins, popups, controls, bounds) can be
 * checked in jsdom.
 */
const fitBounds = vi.fn();
const map = { fitBounds, getContainer: () => document.createElement("div"), on: vi.fn(), off: vi.fn() };
vi.mock("leaflet/dist/leaflet.css", () => ({}));
vi.mock("@/features/real-estate/property-map.css", () => ({}));
vi.mock("leaflet", () => ({ default: { divIcon: (options: { html: string }) => ({ html: options.html }) } }));
vi.mock("react-leaflet", () => ({
  MapContainer: ({ children }: { children: ReactNode }) => <div data-testid="map">{children}</div>,
  TileLayer: ({ url, attribution }: { url: string; attribution: string }) => <div data-testid="tiles" data-url={url} data-attribution={attribution} />,
  ZoomControl: ({ position, zoomInTitle }: { position: string; zoomInTitle: string }) => <div data-testid="zoom" data-position={position} title={zoomInTitle} />,
  Marker: ({ children, title, icon }: { children: ReactNode; title: string; icon: { html: string } }) => (
    <div data-testid="marker" title={title} data-icon={icon.html}>
      {children}
    </div>
  ),
  Popup: ({ children }: { children: ReactNode }) => <div data-testid="popup">{children}</div>,
  useMap: () => map,
}));

const { default: PropertyMapLeaflet } = await import("@/features/real-estate/property-map-leaflet");

const listings: MapListing[] = [
  { number: "C-1", title: "Marina 2BR", price: { amount: "95000.00", currency: "AED" }, lat: 25.08, lng: 55.14, featured: true, image: "https://cdn.example/1.jpg", place: "Marina, Dubai" },
  { number: "C-2", title: "Villa <b>", price: null, lat: 25.2, lng: 55.3, featured: false },
];

beforeEach(() => fitBounds.mockReset());

describe("PropertyMapLeaflet", () => {
  it("renders a price pin and a popup card linking to each listing, fitted to the pins", () => {
    renderWithProviders(<PropertyMapLeaflet listings={listings} listingBase="/uae-en/property" />);
    const markers = screen.getAllByTestId("marker");
    expect(markers).toHaveLength(2);
    expect(markers[0].getAttribute("data-icon")).toContain("AED 95K");
    expect(markers[0].getAttribute("data-icon")).toContain("nawy-map-pin__label--featured");
    expect(markers[1].getAttribute("data-icon")).toContain("Price on request");
    expect(markers[0].title).toBe("Marina 2BR · AED 95,000.00");

    const popup = within(markers[0]).getByTestId("popup");
    expect(within(popup).getByText("Marina, Dubai")).toBeTruthy();
    expect(within(popup).getByText("Featured")).toBeTruthy();
    expect(within(popup).getByRole("link", { name: "View property" }).getAttribute("href")).toBe("/uae-en/property/C-1");

    expect(fitBounds).toHaveBeenCalledWith(
      [
        [25.08, 55.14],
        [25.2, 55.3],
      ],
      expect.objectContaining({ maxZoom: 15 }),
    );
    expect(screen.getByTestId("tiles").getAttribute("data-url")).toBe("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png");
    expect(screen.getByTestId("zoom").getAttribute("data-position")).toBe("topright");
  });

  it("keeps the map LTR inside an Arabic page while popups read RTL", () => {
    const { container } = renderWithProviders(<PropertyMapLeaflet listings={listings} listingBase="/uae-ar/property" />, { market: "uae-ar" });
    expect(container.querySelector(".nawy-map")?.getAttribute("dir")).toBe("ltr");
    const popup = screen.getAllByTestId("popup")[0].firstElementChild!;
    expect(popup.getAttribute("dir")).toBe("rtl");
    expect(within(popup as HTMLElement).getByRole("link").textContent).toBe("عرض العقار");
    expect(screen.getByTestId("zoom").getAttribute("data-position")).toBe("topleft");
  });
});

describe("PropertyMap", () => {
  it("shows the empty state when no listing has coordinates", async () => {
    const { PropertyMap } = await import("@/features/real-estate/property-map");
    renderWithProviders(<PropertyMap listings={[]} listingBase="/uae-en/property" total={4} />);
    expect(screen.getByText("No properties to show on the map")).toBeTruthy();
    expect(screen.queryByTestId("map")).toBeNull();
  });

  it("says how many listings are on the map and how many have no location", async () => {
    const { PropertyMap } = await import("@/features/real-estate/property-map");
    renderWithProviders(<PropertyMap listings={listings} listingBase="/uae-en/property" total={5} />);
    expect(screen.getByText(/2 properties on the map/).textContent).toContain("3 more have no location set.");
  });
});
