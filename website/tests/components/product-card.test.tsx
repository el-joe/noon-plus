import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ProductCard, type ProductCardData } from "@/components/product/product-card";
import * as api from "@/lib/api/endpoints";
import { cart, money, renderWithProviders } from "../helpers/render";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }), usePathname: () => "/uae-en" }));
vi.mock("@/lib/api/endpoints", () => ({
  setCartItem: vi.fn(),
  recordAdClick: vi.fn(),
  getWishlist: vi.fn(),
  getMe: vi.fn(),
  addToWishlist: vi.fn(),
  removeFromWishlist: vi.fn(),
}));

const product = (overrides: Partial<ProductCardData> = {}): ProductCardData => ({
  slug: "galaxy-s30",
  title: "Galaxy S30",
  brand: "Samsung",
  price: money("3499.00"),
  ...overrides,
});

beforeEach(() => {
  vi.mocked(api.setCartItem).mockReset();
  vi.mocked(api.recordAdClick).mockReset().mockResolvedValue(undefined as never);
  push.mockReset();
});

describe("ProductCard", () => {
  it("renders title, currency and grouped price (no brand line, as the design), and links into the market", () => {
    const { container } = renderWithProviders(<ProductCard product={product()} />);
    expect(screen.getByRole("heading", { level: 3 }).textContent).toBe("Galaxy S30");
    expect(screen.queryByText("Samsung")).toBeNull();
    expect(screen.getByText("AED")).toBeTruthy();
    expect(screen.getByText("3,499.00")).toBeTruthy();
    expect(container.querySelector("a")!.getAttribute("href")).toBe("/uae-en/products/galaxy-s30");
  });

  it("uses the market of the page for links and 3-decimal prices", () => {
    const { container } = renderWithProviders(<ProductCard product={product({ price: money("1250.500", "KWD") })} />, { market: "kwt-ar" });
    expect(screen.getByText("1,250.500")).toBeTruthy();
    expect(screen.getByText("KWD")).toBeTruthy();
    expect(container.querySelector("a")!.getAttribute("href")).toBe("/kwt-ar/products/galaxy-s30");
  });

  it("shows the was-price and the discount percentage", () => {
    renderWithProviders(<ProductCard product={product({ price: money("75.00"), wasPrice: money("100.00") })} />);
    expect(screen.getByText("100.00").className).toContain("line-through");
    expect(screen.getByText("25% off")).toBeTruthy();
  });

  it("hides the was-price when it is not higher than the price", () => {
    renderWithProviders(<ProductCard product={product({ price: money("100.00"), wasPrice: money("90.00") })} />);
    expect(screen.queryByText("90.00")).toBeNull();
    expect(screen.queryByText(/% off/)).toBeNull();
  });

  it("renders no price row when the price is null", () => {
    renderWithProviders(<ProductCard product={product({ price: null })} />);
    expect(screen.queryByText("AED")).toBeNull();
  });

  it("falls back to the image placeholder with the title as label", () => {
    renderWithProviders(<ProductCard product={product({ image: null })} />);
    expect(screen.getByRole("img", { name: "Galaxy S30" })).toBeTruthy();
  });

  it("renders the API image with its alt text", () => {
    const { container } = renderWithProviders(<ProductCard product={product({ image: "/p.png", imageAlt: "Front view" })} />);
    const img = container.querySelector("img")!;
    expect(img.getAttribute("src")).toBe("/p.png");
    expect(img.getAttribute("alt")).toBe("Front view");
  });

  it("labels sponsored cards and records the ad click", () => {
    const { container } = renderWithProviders(<ProductCard product={product({ sponsored: true, adToken: "tok-1" })} />);
    expect(screen.getByText("Ad")).toBeTruthy();
    expect(screen.getByText("Sponsored")).toBeTruthy();
    fireEvent.click(container.querySelector("a")!);
    expect(api.recordAdClick).toHaveBeenCalledWith("uae-en", "tok-1");
  });

  it("does not record clicks on organic cards", () => {
    const { container } = renderWithProviders(<ProductCard product={product()} />);
    fireEvent.click(container.querySelector("a")!);
    expect(api.recordAdClick).not.toHaveBeenCalled();
  });

  it("shows the first merchandising badge, and a deal badge replaces it", () => {
    const badges = [
      { key: "bestseller", label: "Best seller" },
      { key: "new", label: "New" },
    ];
    const { unmount } = renderWithProviders(<ProductCard product={product({ badges })} />);
    expect(screen.getByText("Best seller")).toBeTruthy();
    expect(screen.queryByText("New")).toBeNull();
    unmount();
    renderWithProviders(<ProductCard product={product({ badges, badge: "Deal" })} />);
    expect(screen.getByText("Deal")).toBeTruthy();
    expect(screen.queryByText("Best seller")).toBeNull();
  });

  it("shows the express chip and when it arrives", () => {
    const { unmount } = renderWithProviders(<ProductCard product={product({ delivery: { express: true, key: "tomorrow" } })} />);
    expect(screen.getByText("express")).toBeTruthy();
    expect(screen.getByText("Tomorrow")).toBeTruthy();
    unmount();
    renderWithProviders(<ProductCard product={product({ delivery: { express: false, key: "two_days" } })} />, { market: "uae-ar" });
    expect(screen.queryByText("إكسبرس")).toBeNull();
    expect(screen.getByText("خلال يومين")).toBeTruthy();
  });

  it("groups the review count", () => {
    renderWithProviders(<ProductCard product={product({ rating: { average: "4.6", count: 2341 } })} />);
    expect(screen.getByText("(2,341)")).toBeTruthy();
    expect(screen.getByText("4.6")).toBeTruthy();
  });

  it("shows the rating badge only with reviews", () => {
    const { unmount } = renderWithProviders(<ProductCard product={product({ rating: { average: "4.25", count: 12 } })} />);
    expect(screen.getByText("(12)")).toBeTruthy();
    expect(screen.getByText("4.3")).toBeTruthy();
    unmount();
    renderWithProviders(<ProductCard product={product({ rating: { average: null, count: 0 } })} />);
    expect(screen.queryByText("(0)")).toBeNull();
  });

  it("shows the claimed bar and sold-out state for deals", () => {
    const { container, unmount } = renderWithProviders(<ProductCard product={product({ listingId: 5, claimedPercent: 140 })} />);
    expect(screen.getByText("140% claimed")).toBeTruthy();
    expect((container.querySelector(".bg-danger") as HTMLElement).style.width).toBe("100%");
    unmount();
    renderWithProviders(<ProductCard product={product({ listingId: 5, claimedPercent: 100, soldOut: true })} />);
    expect(screen.getByText("Sold out")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Add to cart" })).toBeNull();
  });

  it("quick-adds one unit of the listing to the cart", async () => {
    vi.mocked(api.setCartItem).mockResolvedValue({ data: cart(), meta: {} });
    renderWithProviders(<ProductCard product={product({ listingId: 42 })} />);
    fireEvent.click(screen.getByRole("button", { name: "Add to cart" }));
    await waitFor(() => expect(api.setCartItem).toHaveBeenCalledWith("uae-en", 42, 1));
  });

  it("has no quick-add without a listing", () => {
    renderWithProviders(<ProductCard product={product({ listingId: null })} />);
    expect(screen.queryByRole("button", { name: "Add to cart" })).toBeNull();
  });

  it("sends guests to sign-in from the wishlist heart", () => {
    renderWithProviders(<ProductCard product={product()} />);
    fireEvent.click(screen.getByRole("button", { name: "Add to wishlist" }));
    expect(push).toHaveBeenCalledWith(`/uae-en/login?next=${encodeURIComponent("/")}`);
  });

  it("hides the heart when wishlist is false or there is no slug", () => {
    const { unmount } = renderWithProviders(<ProductCard product={product({ wishlist: false })} />);
    expect(screen.queryByRole("button", { name: "Add to wishlist" })).toBeNull();
    unmount();
    const { container } = renderWithProviders(<ProductCard product={product({ slug: null })} />);
    expect(screen.queryByRole("button", { name: "Add to wishlist" })).toBeNull();
    expect(container.querySelector("a")!.getAttribute("href")).toBe("/uae-en");
  });
});
