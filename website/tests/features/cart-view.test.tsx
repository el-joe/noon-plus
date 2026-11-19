import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CartView } from "@/features/cart/cart-view";
import { MergeWarningsBanner } from "@/features/cart/merge-warnings-banner";
import * as api from "@/lib/api/endpoints";
import type { Cart } from "@/lib/api/schemas";
import { MERGE_WARNINGS_KEY } from "@/lib/merge-warnings";
import { cart, cartLine, money, renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }) }));
vi.mock("@/lib/api/endpoints", () => ({
  getCart: vi.fn(),
  getMe: vi.fn(),
  setCartItem: vi.fn(),
  removeCartItem: vi.fn(),
  setCartWarranty: vi.fn(),
  setCartShipping: vi.fn(),
  setCartMeasurements: vi.fn(),
  applyCoupon: vi.fn(),
  removeCoupon: vi.fn(),
  addToWishlist: vi.fn(),
}));

const HINT = "nawy_cart_hint_uae";

function serve(c: Cart) {
  vi.mocked(api.getCart).mockResolvedValue({ data: c, meta: {} });
}

beforeEach(() => {
  document.cookie = `${HINT}=1; path=/`;
  for (const fn of Object.values(api)) vi.mocked(fn as () => unknown).mockReset();
});
afterEach(() => {
  document.cookie = `${HINT}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
});

describe("CartView", () => {
  it("shows the empty state when the cart has no lines", async () => {
    serve(cart({}, []));
    renderWithProviders(<CartView />);
    expect(await screen.findByText("Your cart is empty")).toBeTruthy();
  });

  it("renders each line with title, link, unit price and quantity", async () => {
    serve(cart({}, [cartLine({ quantity: 2, sku: "SKU-1", unit_price: money("1299.00"), regular_price: money("1299.00") })]));
    renderWithProviders(<CartView />);
    // Image and title both link to the product.
    const links = await screen.findAllByRole("link", { name: "Wireless Earbuds" });
    expect(links.map((a) => a.getAttribute("href"))).toEqual(["/uae-en/products/wireless-earbuds", "/uae-en/products/wireless-earbuds"]);
    expect(screen.getByText("1,299.00")).toBeTruthy();
    expect(screen.getByText("SKU-1")).toBeTruthy();
    const qty = screen.getByRole("group", { name: "Quantity" });
    expect(within(qty).getByText("2")).toBeTruthy();
    expect(screen.getByText("Sold by")).toBeTruthy();
    expect(screen.getByText("Nawy Store")).toBeTruthy();
  });

  it("shows the regular price struck through for discounted lines", async () => {
    serve(cart({}, [cartLine({ unit_price: money("80.00"), regular_price: money("100.00") })]));
    renderWithProviders(<CartView />);
    expect((await screen.findByText("100.00")).className).toContain("line-through");
  });

  it("changes quantity and removes lines through the cart API", async () => {
    serve(cart({}, [cartLine({ listing_id: 7, quantity: 2 })]));
    vi.mocked(api.setCartItem).mockResolvedValue({ data: cart(), meta: {} });
    vi.mocked(api.removeCartItem).mockResolvedValue({ data: cart({}, []), meta: {} });
    renderWithProviders(<CartView />);
    fireEvent.click(await screen.findByRole("button", { name: "Increase quantity" }));
    await waitFor(() => expect(api.setCartItem).toHaveBeenCalledWith("uae-en", 7, 3));
    fireEvent.click(screen.getByRole("button", { name: "Decrease quantity" }));
    await waitFor(() => expect(api.setCartItem).toHaveBeenCalledWith("uae-en", 7, 1));
    fireEvent.click(screen.getByRole("button", { name: "Remove" }));
    await waitFor(() => expect(api.removeCartItem).toHaveBeenCalledWith("uae-en", 7));
  });

  it("caps the quantity at 50", async () => {
    serve(cart({}, [cartLine({ quantity: 50 })]));
    renderWithProviders(<CartView />);
    expect(((await screen.findByRole("button", { name: "Increase quantity" })) as HTMLButtonElement).disabled).toBe(true);
  });

  it("shows the free delivery bar from the cart and a checkout link", async () => {
    serve(cart({ free_delivery: { threshold: money("200.00"), remaining: money("100.00"), progress_percent: 50, qualified: false } }));
    renderWithProviders(<CartView />);
    expect(await screen.findByText("Spend AED 100.00 more for free delivery")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Checkout" }).getAttribute("href")).toBe("/uae-en/checkout");
  });

  it("blocks checkout while made-to-order measurements are missing", async () => {
    serve(cart({}, [cartLine({ fulfilment_mode: "made_to_order", measurements_required: true, measurements_missing: true, product_slug: null })]));
    renderWithProviders(<CartView />);
    await screen.findByText("Wireless Earbuds");
    expect(screen.queryByRole("link", { name: "Checkout" })).toBeNull();
  });
});

describe("MergeWarningsBanner", () => {
  afterEach(() => window.sessionStorage.clear());

  it("shows stashed merge warnings once, with counts and a fallback message", async () => {
    window.sessionStorage.setItem(
      MERGE_WARNINGS_KEY,
      JSON.stringify([{ listing_id: 1, code: "stock", message: "Only 2 left of Earbuds.", requested: 5, merged: 2 }, { listing_id: 2, code: "gone" }]),
    );
    renderWithProviders(<MergeWarningsBanner />);
    expect(await screen.findByText("We couldn't move everything from your guest cart")).toBeTruthy();
    expect(screen.getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Only 2 left of Earbuds. (asked for 5, kept 2)",
      "An item from your guest cart could not be added.",
    ]);
    expect(screen.getByRole("link", { name: "Review your cart" }).getAttribute("href")).toBe("/uae-en/cart");
    expect(window.sessionStorage.getItem(MERGE_WARNINGS_KEY)).toBeNull();
  });

  it("can be dismissed", async () => {
    window.sessionStorage.setItem(MERGE_WARNINGS_KEY, JSON.stringify([{ code: "x" }]));
    renderWithProviders(<MergeWarningsBanner />);
    fireEvent.click(await screen.findByRole("button", { name: "Dismiss" }));
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("renders nothing without warnings", () => {
    const { container } = renderWithProviders(<MergeWarningsBanner />);
    expect(container.innerHTML).toBe("");
  });
});
