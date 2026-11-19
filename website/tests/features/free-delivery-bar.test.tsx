import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { FreeDeliveryBar } from "@/features/cart/free-delivery-bar";
import type { FreeDelivery } from "@/lib/api/schemas";
import { money, renderWithProviders } from "../helpers/render";

const fd = (overrides: Partial<FreeDelivery> = {}): FreeDelivery => ({
  threshold: money("200.00"),
  remaining: money("50.00"),
  progress_percent: 75,
  qualified: false,
  ...overrides,
});

describe("FreeDeliveryBar", () => {
  it("renders nothing without a threshold", () => {
    const { container } = renderWithProviders(<FreeDeliveryBar freeDelivery={null} />);
    expect(container.innerHTML).toBe("");
  });

  it("shows the remaining amount, the threshold and the progress", () => {
    renderWithProviders(<FreeDeliveryBar freeDelivery={fd()} />);
    expect(screen.getByText("Spend AED 50.00 more for free delivery")).toBeTruthy();
    expect(screen.getByText("Free delivery on orders over AED 200.00")).toBeTruthy();
    const bar = screen.getByRole("progressbar", { name: "Progress to free delivery" });
    expect(bar.getAttribute("aria-valuenow")).toBe("75");
    expect((bar.firstElementChild as HTMLElement).style.width).toBe("75%");
  });

  it("shows the qualified message and hides the threshold once qualified", () => {
    renderWithProviders(<FreeDeliveryBar freeDelivery={fd({ qualified: true, progress_percent: 100, remaining: money("0.00") })} />);
    expect(screen.getByText("You qualify for free delivery")).toBeTruthy();
    expect(screen.queryByText(/Free delivery on orders over/)).toBeNull();
  });

  it("clamps and rounds the progress", () => {
    const { unmount } = renderWithProviders(<FreeDeliveryBar freeDelivery={fd({ progress_percent: 140 })} />);
    expect(screen.getByRole("progressbar").getAttribute("aria-valuenow")).toBe("100");
    unmount();
    const second = renderWithProviders(<FreeDeliveryBar freeDelivery={fd({ progress_percent: -5 })} />);
    expect(screen.getByRole("progressbar").getAttribute("aria-valuenow")).toBe("0");
    second.unmount();
    renderWithProviders(<FreeDeliveryBar freeDelivery={fd({ progress_percent: 33.6 })} />);
    expect(screen.getByRole("progressbar").getAttribute("aria-valuenow")).toBe("34");
  });

  it("hides the threshold line in compact mode", () => {
    renderWithProviders(<FreeDeliveryBar freeDelivery={fd()} compact />);
    expect(screen.queryByText(/Free delivery on orders over/)).toBeNull();
  });

  it("formats 3-decimal currencies in Arabic", () => {
    renderWithProviders(<FreeDeliveryBar freeDelivery={fd({ remaining: money("1.500", "OMR"), threshold: money("10.000", "OMR") })} />, { market: "omn-ar" });
    expect(screen.getByText("أضف مشتريات بقيمة OMR 1.500 للحصول على توصيل مجاني")).toBeTruthy();
  });
});
