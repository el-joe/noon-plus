import { fireEvent, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { CategoryNav } from "@/components/layout/mega-menu";
import { renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }), usePathname: () => "/uae-en" }));
vi.mock("@/lib/api/endpoints", () => ({
  getCategories: vi.fn(async () => ({
    data: [{ id: 1, slug: "electronics", name: "Electronics", children: [{ id: 2, slug: "mobiles", name: "Mobiles", children: [] }] }],
    meta: {},
  })),
}));

describe("CategoryNav", () => {
  it("closes the open mega menu when a nav-bar category is clicked (it navigates)", async () => {
    renderWithProviders(<CategoryNav />);
    const navLink = await screen.findByRole("link", { name: "Electronics" });
    fireEvent.click(screen.getByRole("button", { name: /All categories/ }));
    expect(screen.getByRole("button", { name: /All categories/ }).getAttribute("aria-expanded")).toBe("true");
    navLink.addEventListener("click", (e) => e.preventDefault());
    fireEvent.click(navLink);
    expect(screen.getByRole("button", { name: /All categories/ }).getAttribute("aria-expanded")).toBe("false");
  });
});
