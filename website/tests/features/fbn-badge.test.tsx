import { describe, expect, it } from "vitest";
import { FulfilledByNawyBadge, isFulfilledByNawy } from "@/features/product/fbn-badge";
import { offer } from "@/lib/api/schemas";
import { renderWithProviders } from "../helpers/render";

describe("FulfilledByNawyBadge", () => {
  it("shows the badge for FBN offers, in English and Arabic", () => {
    const { getByTestId } = renderWithProviders(<FulfilledByNawyBadge offer={{ fulfilment: "fbn", fulfilled_by_nawy: true }} />);
    expect(getByTestId("fbn-badge").textContent).toContain("Fulfilled by Nawy");

    const arabic = renderWithProviders(<FulfilledByNawyBadge offer={{ fulfilment: "fbn" }} />, { market: "omn-ar" });
    expect(arabic.container.querySelector("[data-testid=fbn-badge]")?.textContent).toContain("الاستيفاء بواسطة ناوي");
  });

  it("shows nothing for seller-fulfilled offers", () => {
    const { container } = renderWithProviders(<FulfilledByNawyBadge offer={{ fulfilment: "fbm", fulfilled_by_nawy: false }} />);
    expect(container.textContent).toBe("");
    expect(isFulfilledByNawy({ fulfilment: "cross_dock" })).toBe(false);
  });

  it("reads the API flag from the offer resource", () => {
    const parsed = offer.parse({
      listing_id: 1, sku: "SKU-1", seller: { slug: "s", name: "S", rating: null }, price: null, was_price: null, flash_sale: null,
      delivery: null, fulfilment: "fbn", fulfilled_by_nawy: true, condition: "new", in_stock: 3,
    });
    expect(isFulfilledByNawy(parsed)).toBe(true);
  });
});
