import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AddressFormModal } from "@/features/account/address-form";
import * as api from "@/lib/api/endpoints";
import type { Address } from "@/lib/api/schemas";
import { renderWithProviders } from "../helpers/render";

vi.mock("@/lib/api/endpoints", () => ({ getDeliveryAreas: vi.fn(), createAddress: vi.fn(), updateAddress: vi.fn() }));

const fill = (container: HTMLElement, name: string, value: string) => {
  const input = container.querySelector(`[name="${name}"]`) as HTMLInputElement | HTMLSelectElement;
  fireEvent.change(input, { target: { value } });
};

beforeEach(() => {
  vi.mocked(api.createAddress).mockReset().mockResolvedValue({ data: { id: 9 } as Address, meta: {} });
});

describe("AddressFormModal", () => {
  it("sends the typed city when the market has no delivery areas (E2E-004)", async () => {
    vi.mocked(api.getDeliveryAreas).mockResolvedValue({ data: [], meta: {} });
    const { container } = renderWithProviders(<AddressFormModal onClose={() => undefined} />);
    await waitFor(() => expect(container.querySelector('[name="city_free"]')).not.toBeNull());
    fill(container, "name", "Sara Ahmed");
    fill(container, "phone", "+971501234567");
    fill(container, "city_free", "Dubai");
    fill(container, "area", "Marina");
    fill(container, "street", "Gate 2");
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(api.createAddress).toHaveBeenCalled());
    expect(vi.mocked(api.createAddress).mock.calls[0][1]).toMatchObject({ city: "Dubai", area: "Marina", street: "Gate 2" });
  });

  it("sends the chosen city from the delivery-area list", async () => {
    vi.mocked(api.getDeliveryAreas).mockResolvedValue({ data: [{ city: "Dubai", zone: null, areas: [{ name: "Marina", zone: { id: 1, name: "Dubai" } }, { name: "JLT", zone: { id: 1, name: "Dubai" } }] }], meta: {} });
    const { container } = renderWithProviders(<AddressFormModal onClose={() => undefined} />);
    await screen.findByRole("option", { name: "Dubai" });
    const citySelect = [...container.querySelectorAll("select")].find((s) => [...s.options].some((o) => o.value === "Dubai"))!;
    fireEvent.change(citySelect, { target: { value: "Dubai" } });
    fill(container, "name", "Sara Ahmed");
    fill(container, "phone", "+971501234567");
    await screen.findByRole("option", { name: "JLT" });
    fill(container, "area", "JLT");
    fill(container, "street", "Gate 2");
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(api.createAddress).toHaveBeenCalled());
    expect(vi.mocked(api.createAddress).mock.calls[0][1]).toMatchObject({ city: "Dubai", area: "JLT" });
  });
});
