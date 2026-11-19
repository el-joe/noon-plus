import { describe, expect, it } from "vitest";
import { StatusBadge } from "@/components/ui/status-badge";
import { renderWithProviders } from "../helpers/render";

const badge = (container: HTMLElement) => container.querySelector("span")!;

describe("StatusBadge", () => {
  it("shows the translated label in English", () => {
    const { container } = renderWithProviders(<StatusBadge status="out_for_delivery" />);
    expect(badge(container).textContent).toBe("Out for delivery");
  });

  it("shows the Arabic label in an Arabic market", () => {
    const { container } = renderWithProviders(<StatusBadge status="delivered" />, { market: "omn-ar" });
    expect(badge(container).textContent).toBe("تم التوصيل");
  });

  it("humanizes statuses without a translation", () => {
    const { container } = renderWithProviders(<StatusBadge status="awaiting_carrier_pickup" />);
    expect(badge(container).textContent).toBe("Awaiting carrier pickup");
  });

  it.each([
    ["delivered", "bg-success-subtle"],
    ["cancelled", "text-danger-text"],
    ["shipped", "bg-info-subtle"],
    ["pending", "bg-warning-subtle"],
    ["something_new", "bg-subtle"],
  ])("uses the right tone for %s", (status, cls) => {
    const { container } = renderWithProviders(<StatusBadge status={status} />);
    expect(badge(container).className).toContain(cls);
  });
});
