import { describe, expect, it, vi } from "vitest";
import { Shipment } from "@/features/account/order-detail";
import { order as orderSchema, type Order } from "@/lib/api/schemas";
import { money, renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }), usePathname: () => "/uae-en/account/orders/NW-AE-1", useSearchParams: () => new URLSearchParams() }));

/** Owner decision 2026-11-19: a seller's FBN and seller-shipped items arrive as two parcels. */
const parcel = (number: string, fulfilledBy: string, title: string) => ({
  number,
  status: "placed",
  seller: "Store",
  fulfilled_by: fulfilledBy,
  total: money("10.00"),
  shipping: money("0.00"),
  method: "standard",
  delivery: { from: null, by: null },
  delivery_code: null,
  actions: { track: true },
  items: [{ id: number.length, sku: "SKU", title, quantity: 1, unit_price: money("10.00"), line_total: money("10.00"), warranty_total: money("0.00") }],
});

const splitOrder: Order = orderSchema.parse({
  number: "NW-AE-1",
  status: "placed",
  payment_method: "cod",
  payment_status: "pending",
  payment_due_at: null,
  payment: null,
  placed_at: "2026-11-19T10:00:00Z",
  totals: { subtotal: money("20.00"), discount: null, shipping: null, cod_fee: null, warranty: null, total: money("20.00"), tax_included: null },
  shipments: [parcel("NW-AE-1-1", "seller", "Seller item"), parcel("NW-AE-1-2", "nawy", "Warehouse item")],
  shipping_address: null,
  invoice_available: false,
});

describe("split FBN parcels", () => {
  it("keeps fulfilled_by from the order API", () => {
    expect(splitOrder.shipments.map((s) => s.fulfilled_by)).toEqual(["seller", "nawy"]);
  });

  it("marks the warehouse parcel as shipped by Nawy, in English and Arabic", () => {
    const warehouse = renderWithProviders(<Shipment order={splitOrder} shipment={splitOrder.shipments[1]} index={1} onUpdated={() => {}} />);
    expect(warehouse.container.textContent).toContain("Shipment 2 of 2");
    expect(warehouse.container.querySelector("[data-fulfilled-by=nawy]")?.textContent).toContain("shipped by Nawy");

    const arabic = renderWithProviders(<Shipment order={splitOrder} shipment={splitOrder.shipments[1]} index={1} onUpdated={() => {}} />, { market: "omn-ar" });
    expect(arabic.container.querySelector("[data-fulfilled-by=nawy]")?.textContent).toContain("ناوي");
  });

  it("shows no Nawy mark on the seller's parcel", () => {
    const seller = renderWithProviders(<Shipment order={splitOrder} shipment={splitOrder.shipments[0]} index={0} onUpdated={() => {}} />);
    expect(seller.container.querySelector("[data-fulfilled-by]")).toBeNull();
    expect(seller.container.textContent).toContain("Shipment 1 of 2");
  });
});
