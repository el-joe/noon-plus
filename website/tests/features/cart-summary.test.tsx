import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { CartWarnings, CouponBox, Totals } from "@/features/cart/summary";
import { ApiError } from "@/lib/api/client";
import * as api from "@/lib/api/endpoints";
import { cart, cartLine, money, renderWithProviders } from "../helpers/render";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }) }));
vi.mock("@/lib/api/endpoints", () => ({ applyCoupon: vi.fn(), removeCoupon: vi.fn() }));

beforeEach(() => {
  vi.mocked(api.applyCoupon).mockReset();
  vi.mocked(api.removeCoupon).mockReset();
  push.mockReset();
});

/** Text of the value cell next to a label. */
const valueOf = (label: string | RegExp) => screen.getByText(label).nextElementSibling?.textContent;

describe("Totals", () => {
  it("shows subtotal with the item count, free shipping, VAT and total", () => {
    renderWithProviders(<Totals cart={cart({}, [cartLine({ quantity: 2 }), cartLine({ listing_id: 2, quantity: 1 })])} />);
    expect(valueOf("Subtotal (3 items)")).toBe("AED 100.00");
    expect(valueOf("Shipping")).toBe("FREE");
    expect(valueOf("VAT (5%) included")).toBe("AED 4.76");
    expect(valueOf("Total (incl. VAT)")).toBe("AED 100.00");
  });

  it("shows paid shipping, coupons, warranty and COD fee when present", () => {
    const c = cart({
      coupons: [{ code: "SAVE10", scope: null, discount: money("10.00"), free_shipping: false }],
      totals: { ...cart().totals, shipping: money("15.00"), warranty: money("29.00"), cod_fee: money("5.00") },
    });
    renderWithProviders(<Totals cart={c} />);
    expect(valueOf("Shipping")).toBe("AED 15.00");
    expect(valueOf("Discount SAVE10")).toBe("− AED 10.00");
    expect(valueOf("Extended warranty")).toBe("AED 29.00");
    expect(valueOf("Cash on delivery fee")).toBe("AED 5.00");
  });

  it("shows a coupon-less discount and hides zero rows", () => {
    renderWithProviders(<Totals cart={cart({ totals: { ...cart().totals, discount: money("7.50"), tax_rate: null } })} />);
    expect(valueOf("Discount")).toBe("− AED 7.50");
    expect(screen.queryByText("Extended warranty")).toBeNull();
    expect(screen.queryByText(/VAT \(/)).toBeNull();
  });

  it("keeps 3 decimals for Omani rials in Arabic", () => {
    const c = cart({ totals: { ...cart().totals, total: money("12.345", "OMR"), subtotal: money("12.345", "OMR"), tax_rate: "0" } });
    renderWithProviders(<Totals cart={c} />, { market: "omn-ar" });
    expect(screen.getAllByText("OMR 12.345").length).toBe(2);
  });
});

describe("CouponBox", () => {
  const input = () => screen.getByRole("textbox", { name: "Coupon code" }) as HTMLInputElement;
  const applyButton = () => screen.getByRole("button", { name: "Apply" }) as HTMLButtonElement;

  it("upper-cases the code and only enables Apply with a code", () => {
    renderWithProviders(<CouponBox cart={cart()} />);
    expect(applyButton().disabled).toBe(true);
    fireEvent.change(input(), { target: { value: "save10" } });
    expect(input().value).toBe("SAVE10");
    expect(applyButton().disabled).toBe(false);
    fireEvent.change(input(), { target: { value: "   " } });
    expect(applyButton().disabled).toBe(true);
  });

  it("applies a trimmed code and clears the input on success", async () => {
    vi.mocked(api.applyCoupon).mockResolvedValue({ data: cart(), meta: {} });
    renderWithProviders(<CouponBox cart={cart()} />);
    fireEvent.change(input(), { target: { value: " save10 " } });
    fireEvent.click(applyButton());
    await waitFor(() => expect(api.applyCoupon).toHaveBeenCalledWith("uae-en", "SAVE10"));
    await waitFor(() => expect(input().value).toBe(""));
  });

  it("shows the API error message", async () => {
    vi.mocked(api.applyCoupon).mockRejectedValue(new ApiError(422, "coupon_expired", "This coupon has expired."));
    renderWithProviders(<CouponBox cart={cart()} />);
    fireEvent.change(input(), { target: { value: "OLD" } });
    fireEvent.click(applyButton());
    expect((await screen.findByRole("alert")).textContent).toBe("This coupon has expired.");
    expect(screen.queryByRole("button", { name: "Sign in with email" })).toBeNull();
  });

  it("shows the first field error over the message", async () => {
    vi.mocked(api.applyCoupon).mockRejectedValue(new ApiError(422, "validation_failed", "Invalid", { code: ["Unknown coupon code."] }));
    renderWithProviders(<CouponBox cart={cart()} />);
    fireEvent.change(input(), { target: { value: "NOPE" } });
    fireEvent.click(applyButton());
    expect((await screen.findByRole("alert")).textContent).toBe("Unknown coupon code.");
  });

  it("falls back to the generic error on network failures", async () => {
    vi.mocked(api.applyCoupon).mockRejectedValue(new ApiError(0, "network_error", "Failed to fetch"));
    renderWithProviders(<CouponBox cart={cart()} />);
    fireEvent.change(input(), { target: { value: "X" } });
    fireEvent.click(applyButton());
    expect((await screen.findByRole("alert")).textContent).toBe("Something went wrong. Please try again.");
  });

  it("offers sign-in for coupons that need an account", async () => {
    vi.mocked(api.applyCoupon).mockRejectedValue(new ApiError(403, "coupon_sign_in_required", "Sign in to use this coupon."));
    renderWithProviders(<CouponBox cart={cart()} />);
    fireEvent.change(input(), { target: { value: "FIRST" } });
    fireEvent.click(applyButton());
    fireEvent.click(await screen.findByRole("button", { name: "Sign in with email" }));
    expect(push).toHaveBeenCalledWith(`/uae-en/login?next=${encodeURIComponent("/")}`);
  });

  it("lists applied coupons and removes one", async () => {
    vi.mocked(api.removeCoupon).mockResolvedValue({ data: cart(), meta: {} });
    renderWithProviders(<CouponBox cart={cart({ coupons: [{ code: "SAVE10", scope: null, discount: money("10.00"), free_shipping: false }] })} />);
    expect(screen.getByText("SAVE10 applied")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Remove SAVE10" }));
    await waitFor(() => expect(api.removeCoupon).toHaveBeenCalledWith("uae-en", "SAVE10"));
  });
});

describe("CartWarnings", () => {
  it("renders nothing without warnings", () => {
    const { container } = renderWithProviders(<CartWarnings cart={cart()} />);
    expect(container.innerHTML).toBe("");
  });

  it("lists the API's warning strings", () => {
    renderWithProviders(<CartWarnings cart={cart({ warnings: ["Coupon SAVE10 no longer applies.", "", 42] })} />);
    expect(screen.getByText("Please check your cart")).toBeTruthy();
    expect(screen.getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Coupon SAVE10 no longer applies."]);
  });

  it("falls back to the generic warning for non-string warnings", () => {
    renderWithProviders(<CartWarnings cart={cart({ warnings: [{ code: "price_changed" }] })} />);
    expect(screen.getByRole("status").textContent).toBe("Some items changed since you added them. Please review your cart.");
  });
});
