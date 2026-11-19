import { describe, expect, it } from "vitest";
import { block, cart, cmsPage, money, productCard } from "@/lib/api/schemas";
import { cardFromApi } from "@/lib/api/mappers";
import { cart as cartFixture } from "../helpers/render";

describe("money", () => {
  it("requires a string amount (minor units stay exact)", () => {
    expect(money.safeParse({ amount: "1.250", currency: "OMR" }).success).toBe(true);
    expect(money.safeParse({ amount: 1.25, currency: "OMR" }).success).toBe(false);
  });
});

describe("productCard", () => {
  const base = { slug: "tv", title: "TV", price: { amount: "999.00", currency: "AED" } };

  it("defaults sponsored to false and strips unknown keys", () => {
    const parsed = productCard.parse({ ...base, secret: "x" });
    expect(parsed.sponsored).toBe(false);
    expect("secret" in parsed).toBe(false);
  });

  it("accepts a null price", () => {
    expect(productCard.parse({ ...base, price: null }).price).toBeNull();
  });

  it("falls back to [] for malformed badges instead of failing the card", () => {
    expect(productCard.parse({ ...base, badges: "bestseller" }).badges).toEqual([]);
    expect(productCard.parse({ ...base, badges: [{ key: "new", label: "New" }] }).badges).toEqual([{ key: "new", label: "New" }]);
  });

  it("maps to the card component's data with cardFromApi", () => {
    const parsed = productCard.parse({ ...base, listing_id: 9, image: { id: 1, url: "/i.png", alt: "Alt" }, rating: { average: "4.5", count: 3 } });
    expect(cardFromApi(parsed)).toEqual({
      slug: "tv",
      listingId: 9,
      title: "TV",
      brand: undefined,
      price: { amount: "999.00", currency: "AED" },
      sponsored: false,
      label: undefined,
      adToken: undefined,
      image: "/i.png",
      imageAlt: "Alt",
      rating: { average: "4.5", count: 3 },
      badges: [],
    });
  });
});

describe("block / cmsPage", () => {
  it("turns PHP's empty-array settings into {}", () => {
    expect(block.parse({ type: "html", settings: [] }).settings).toEqual({});
    expect(block.parse({ type: "html" }).settings).toEqual({});
  });

  it("turns empty-array zones into {}", () => {
    expect(cmsPage.parse({ slug: "home", template: "default", title: null, zones: [] }).zones).toEqual({});
  });

  it("keeps unknown block types (the renderer decides)", () => {
    const page = cmsPage.parse({ slug: "home", template: "default", title: "Home", zones: { main: [{ type: "from_the_future", settings: { a: 1 } }] } });
    expect(page.zones.main[0].type).toBe("from_the_future");
  });
});

describe("cart", () => {
  it("parses a CartPresenter payload and defaults line flags", () => {
    const fixture = cartFixture();
    const raw = JSON.parse(JSON.stringify(fixture)) as Record<string, unknown>;
    const line = (raw.shipments as { items: Record<string, unknown>[] }[])[0].items[0];
    delete line.measurement_fields;
    delete line.measurements_required;
    delete line.measurements_missing;
    const parsed = cart.parse(raw);
    expect(parsed.shipments[0].items[0].measurements_missing).toBe(false);
    expect(parsed.shipments[0].items[0].measurement_fields).toEqual([]);
  });

  it("drops a malformed free_delivery block instead of failing the cart", () => {
    const parsed = cart.parse({ ...cartFixture(), free_delivery: { threshold: "200" } });
    expect(parsed.free_delivery).toBeNull();
  });

  it("keeps a valid free_delivery block", () => {
    const fd = { threshold: { amount: "200.00", currency: "AED" }, remaining: { amount: "50.00", currency: "AED" }, progress_percent: 75, qualified: false };
    expect(cart.parse({ ...cartFixture(), free_delivery: fd }).free_delivery).toEqual(fd);
  });
});
