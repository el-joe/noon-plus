import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { categoryTreeWithCounts, listingFilters, priceBands, resultCount } from "@/features/listing/product-listing";
import { SponsoredBrandRow, offerCaption, shortMoney } from "@/features/page-builder/blocks/category-promos";
import { SubcategoryTiles } from "@/features/page-builder/blocks/category";
import { PageZone } from "@/features/page-builder/render";
import type { BlockContext } from "@/features/page-builder/settings";
import { getT } from "@/lib/i18n";
import type { Block } from "@/lib/api/schemas";
import { market, renderWithProviders } from "../helpers/render";

vi.mock("next/headers", () => ({ cookies: async () => ({ get: () => undefined }) }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/uae-en/electronics",
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("embla-carousel-react", () => ({ default: vi.fn(() => [() => undefined, undefined]) }));

const card = (slug: string, title: string, category = "smartphones") => ({ slug, title, brand: "Nova", category, price: { amount: "79.00", currency: "AED" }, image: { id: 1, url: "/img/product-smartphone.png", alt: title } });
const ctx = (slug = "uae-en"): BlockContext => ({ market: market(slug), categories: [], category: { slug: "electronics", name: "Electronics", children: [], path: ["electronics"] } });
const zone = (blocks: Block[], slug = "uae-en") => renderWithProviders(<PageZone blocks={blocks} ctx={ctx(slug)} />, { market: slug });

describe("category page helpers", () => {
  it("rounds big result counts like the design (100K+) and keeps small ones exact", () => {
    expect(resultCount(1234, "en")).toBe("1,234");
    expect(resultCount(12_345, "en")).toBe("12K+");
    expect(resultCount(104_000, "en")).toBe("100K+");
    expect(resultCount(1_234_567, "en")).toBe("1.2M+");
  });

  it("reads the new rail filters from the URL and drops unknown values", () => {
    const filters = listingFilters({ arrives: "tomorrow", grade: "A,Z,B", fulfilment: "supermall,fbn,x", price_drop: "1", new_arrivals: "yes" });
    expect(filters).toMatchObject({ arrives: "tomorrow", grade: "A,B", fulfilment: "supermall,fbn", price_drop: "1", new_arrivals: undefined, per_page: 60 });
    expect(listingFilters({ arrives: "someday" }).arrives).toBeUndefined();
  });

  it("splits the price facet into round bands", () => {
    expect(priceBands("19.00", "2199.00")).toEqual([
      { min: null, max: "350" },
      { min: "350", max: "800" },
      { min: "800", max: "1500" },
      { min: "1500", max: null },
    ]);
    expect(priceBands(null, null)).toEqual([]);
    expect(priceBands("10.00", "10.00")).toEqual([]);
  });

  it("formats offer captions from real data, with 3 decimals in OMR", () => {
    const t = getT("en");
    expect(offerCaption(t, market("uae-en"), { amount: "499.00", currency: "AED" }, 0)).toBe("From AED 499");
    expect(offerCaption(t, market("uae-en"), { amount: "499.00", currency: "AED" }, 40)).toBe("Up to 40% off");
    expect(offerCaption(t, market("uae-en"), null, 40, true)).toBe("Up to 40%");
    expect(shortMoney({ amount: "10.1", currency: "OMR" }, market("omn-en"))).toBe("OMR 10.100");
    expect(offerCaption(getT("ar"), market("uae-ar"), { amount: "36.00", currency: "AED" }, 0)).toBe("ابتداءً من AED 36");
  });

  it("builds the rail tree with slugs so facet counts can replace the totals", () => {
    const nodes = [{ slug: "electronics", name: "Electronics", image: null, product_count: 9, children: [{ slug: "mobiles", name: "Mobiles", image: null, product_count: 5, children: [] }] }];
    expect(categoryTreeWithCounts(market("uae-en"), nodes, ["electronics"])).toEqual([
      { label: "Electronics", href: "/uae-en/electronics", active: true, depth: 0, count: 9, slug: "electronics" },
      { label: "Mobiles", href: "/uae-en/electronics/mobiles", active: false, depth: 1, count: 5, slug: "mobiles" },
    ]);
  });
});

describe("category page blocks", () => {
  it("shows sub-category circles with offer captions", () => {
    const context = { ...ctx(), category: { slug: "electronics", name: "Electronics", path: ["electronics"], children: [{ slug: "mobiles", name: "Mobiles", image: null, min_price: { amount: "499.00", currency: "AED" }, max_discount: 0 }, { slug: "audio", name: "Audio", image: null, min_price: null, max_discount: 40 }] } };
    renderWithProviders(<SubcategoryTiles s={{ style: "circle" }} ctx={context} />);
    expect(screen.getByText("From AED 499")).toBeTruthy();
    expect(screen.getByText("Up to 40%")).toBeTruthy();
    expect(screen.getByRole("link", { name: /Mobiles/ }).getAttribute("href")).toBe("/uae-en/electronics/mobiles");
  });

  it("renders brand tiles, the gift band and deal columns from resolved data", () => {
    zone([
      { type: "category_brand_tiles", settings: { category: "electronics" }, data: { tiles: [{ slug: "soundmax", name: "SoundMax", image: { url: "/img/product-earbuds.png" }, min_price: { amount: "36.00", currency: "AED" }, max_discount: 0 }] } },
      { type: "category_gift_band", settings: { title: "Gifts they'll love", sticker: "Under AED 99" }, data: { products: [card("nova-lite", "Nova lite")], categories: { smartphones: "Smartphones" } } },
      { type: "category_deal_columns", settings: { title: "Mega Deals" }, data: { ends_at: "2099-01-01T00:00:00Z", sale_slug: "big-mega-deals", columns: [{ slug: "audio", name: "Audio", product: card("buds", "SoundMax Buds", "audio") }] } },
    ]);
    expect(screen.getByText("From AED 36")).toBeTruthy();
    expect(screen.getByText("Gifts they'll love")).toBeTruthy();
    expect(screen.getByText("Smartphones")).toBeTruthy();
    expect(screen.getByText("Audio deals").closest("a")!.getAttribute("href")).toBe("/uae-en/audio?deals=1");
    expect(screen.getByText("Mega Deals")).toBeTruthy();
  });

  it("renders nothing for blocks without data (no fake content)", () => {
    const { container } = zone([
      { type: "category_brand_tiles", settings: {}, data: null },
      { type: "category_gift_band", settings: { title: "Gifts" }, data: { products: [] } },
      { type: "category_deal_columns", settings: {}, data: null },
    ]);
    expect(container.textContent).toBe("");
  });

  it("labels the sponsored brand row as an ad and links its brand", () => {
    renderWithProviders(
      <SponsoredBrandRow
        market={market("uae-en")}
        ad={{ slot: "cat-brand", image: null, headline: "Hear every detail", link_url: "/brands/soundmax", sponsored: true, label: "Ad", brand: { slug: "soundmax", name: "SoundMax", logo: null }, products: [card("buds", "SoundMax Buds", "audio")] } as never}
      />,
    );
    expect(screen.getByText("Ad")).toBeTruthy();
    expect(screen.getByText("Hear every detail")).toBeTruthy();
    expect(screen.getByRole("link", { name: /Shop SoundMax/ }).getAttribute("href")).toBe("/uae-en/brands/soundmax");
    expect(screen.getByText("SoundMax Buds")).toBeTruthy();
  });
});
