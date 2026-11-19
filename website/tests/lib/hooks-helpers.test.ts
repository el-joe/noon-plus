import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api/client";
import { cartItemCount, errorMessage } from "@/lib/hooks";
import { cart, cartLine } from "../helpers/render";

describe("errorMessage", () => {
  it("uses the fallback for network errors and non-API errors", () => {
    expect(errorMessage(new ApiError(0, "network_error", "Failed to fetch"), "Try again")).toBe("Try again");
    expect(errorMessage(new ApiError(502, "api_unreachable", "x"), "Try again")).toBe("Try again");
    expect(errorMessage(new Error("boom"), "Try again")).toBe("Try again");
  });

  it("prefers the first field error, then the API message", () => {
    expect(errorMessage(new ApiError(422, "validation_failed", "Invalid", { code: ["Coupon expired"] }), "F")).toBe("Coupon expired");
    expect(errorMessage(new ApiError(409, "coupon_min_spend", "Spend more"), "F")).toBe("Spend more");
  });

  it("uses the throttled message with the Retry-After seconds", () => {
    const error = new ApiError(429, "too_many_requests", "Too Many Attempts.", {}, {}, 30);
    expect(errorMessage(error, "F", (s) => `wait ${s}s`)).toBe("wait 30s");
    expect(errorMessage(new ApiError(429, "too_many_requests", "Too Many Attempts."), "F", (s) => `wait ${s}s`)).toBe("Too Many Attempts.");
  });
});

describe("cartItemCount", () => {
  it("sums quantities across shipments", () => {
    const c = cart({}, [cartLine({ quantity: 2 }), cartLine({ listing_id: 2, quantity: 3 })]);
    c.shipments.push({ ...c.shipments[0], items: [cartLine({ listing_id: 3, quantity: 1 })] });
    expect(cartItemCount(c)).toBe(6);
  });

  it("is 0 for no cart", () => {
    expect(cartItemCount(null)).toBe(0);
    expect(cartItemCount(undefined)).toBe(0);
  });
});
