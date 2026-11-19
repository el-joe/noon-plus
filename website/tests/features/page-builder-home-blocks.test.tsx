import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { PageZone } from "@/features/page-builder/render";
import type { BlockContext } from "@/features/page-builder/settings";
import { cardFromApi } from "@/lib/api/mappers";
import { productCard, type Block } from "@/lib/api/schemas";
import { market, renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/uae-en",
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("embla-carousel-react", () => ({ default: vi.fn(() => [() => undefined, undefined]) }));

const ctx = (slug = "uae-en"): BlockContext => ({ market: market(slug), categories: [] });
const zone = (blocks: Block[], slug = "uae-en") => renderWithProviders(<PageZone blocks={blocks} ctx={ctx(slug)} />, { market: slug });

const sale = {
  id: 7,
  slug: "big-mega-deals",
  name: "Big mega deals",
  state: "live",
  starts_at: "2026-11-01T00:00:00Z",
  ends_at: "2099-01-01T00:00:00Z",
  max_per_customer: 2,
  deals: [
    { listing_id: 1, product: { slug: "soundmax-pro", title: "SoundMax Earbuds Pro" }, price: { amount: "249.00", currency: "AED" }, was_price: { amount: "372.00", currency: "AED" }, sold_out: false, claimed_percent: 80 },
  ],
};

describe("home design blocks", () => {
  it("renders the hero row as styled cards with sticker, code chip and a stacked column", () => {
    const { container } = zone([
      {
        type: "hero_bento",
        settings: {
          variant: "cards",
          slots: [
            { tone: "brand", sticker: "Welcome to Nawy", title: "15% Cashback", code: "FIRST15", image: "/img/product-smartphone.png", link: "/deals" },
            { items: [{ tone: "subtle", title_en: "Extra 10% off", title_ar: "خصم إضافي 10%" }, { tone: "success", title: "Today's top deals" }] },
          ],
        },
      },
    ]);
    expect(screen.getByText("15% Cashback")).toBeTruthy();
    expect(screen.getByText("Welcome to Nawy")).toBeTruthy();
    expect(screen.getByText("Code: FIRST15")).toBeTruthy();
    expect(screen.getByText("Extra 10% off")).toBeTruthy();
    expect(container.querySelector('a[href="/uae-en/deals"]')!.className).toContain("bg-brand");
  });

  it("renders a styled banner_full in Arabic and an image banner otherwise", () => {
    const { container } = zone([{ type: "banner_full", settings: { tone: "dark", sticker_ar: "السوق المفتوح", title_ar: "تبحث عن مركبة؟", title: "Searching for a vehicle?", link: "/classified" } }], "uae-ar");
    expect(screen.getByText("تبحث عن مركبة؟")).toBeTruthy();
    expect(screen.getByText("السوق المفتوح")).toBeTruthy();
    expect(container.querySelector("a")!.getAttribute("href")).toBe("/uae-ar/classified");
  });

  it("renders a deal grid from the resolved sale inside a panel row, with red countdown boxes", () => {
    const { container } = zone([
      { type: "panel_row", settings: { columns: [{ width: 1, block: { type: "deal_grid", settings: { title: "Mega deals", flash_sale_slug: "big-mega-deals" }, data: { sale } } }] } },
    ]);
    expect(screen.getByText("Mega deals")).toBeTruthy();
    expect(screen.getByText("SoundMax Earbuds Pro")).toBeTruthy();
    expect(screen.getByText("33% off")).toBeTruthy();
    expect(screen.getByText("Limited stock")).toBeTruthy();
    expect(screen.getByText("All deals").getAttribute("href")).toBe("/uae-en/flash-sale/big-mega-deals");
    const timer = container.querySelector('[role="timer"]')!;
    expect(timer.getAttribute("dir")).toBe("ltr");
    expect(timer.querySelectorAll(".bg-danger")).toHaveLength(3);
  });

  it("hides offer cards whose coupon is not live", () => {
    zone([{ type: "offer_cards", settings: { title: "Maximize your savings", items: [{ code: "GLOW", title: "Free beauty box" }, { code: "OLD", title: "Expired" }] }, data: { codes: ["GLOW"] } }]);
    expect(screen.getByText("Code: GLOW")).toBeTruthy();
    expect(screen.queryByText("Expired")).toBeNull();
  });

  it("renders resolved category tiles and the live pill", () => {
    const { container } = zone([
      {
        type: "tile_scroller",
        settings: { shape: "circle", live_pill: true },
        data: { tiles: [{ label: "Mobiles", image: "http://localhost:8000/storage/products/demo/product-smartphone.png", link: "/electronics/mobiles" }], live: { slug: "tech-deals", title: "Tech deals with Omar" } },
      },
    ]);
    expect(screen.getByText("Mobiles")).toBeTruthy();
    expect(container.querySelector('a[href="/uae-en/electronics/mobiles"]')).toBeTruthy();
    expect(screen.getByText("Tech deals with Omar").closest("a")!.getAttribute("href")).toBe("/uae-en/live/tech-deals");
  });

  it("renders the SEO text with a read-more toggle", () => {
    zone([{ type: "seo_text", settings: { title: "Superior online shopping in the UAE", text: "<p>Nawy brings you millions of products.</p>" } }]);
    expect(screen.getByText("Superior online shopping in the UAE")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Read more" }).getAttribute("aria-expanded")).toBe("false");
  });

  it("maps the design card fields from the API", () => {
    const parsed = productCard.parse({
      slug: "nova-x12",
      title: "Nova X12",
      price: { amount: "2199.00", currency: "AED" },
      was_price: { amount: "2599.00", currency: "AED" },
      discount_percent: 15,
      delivery: { express: true, key: "tomorrow" },
      offers_count: 4,
      listing_id: 9,
    });
    expect(cardFromApi(parsed)).toMatchObject({ wasPrice: { amount: "2599.00" }, delivery: { express: true, key: "tomorrow" }, listingId: 9 });
    expect(productCard.parse({ slug: "a", title: "A", price: null, delivery: { express: true, key: "someday" } }).delivery).toBeNull();
  });
});
