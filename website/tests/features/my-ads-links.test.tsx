import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { MyAdsView } from "@/features/classifieds/account";
import * as api from "@/lib/api/classifieds";
import { renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("@/lib/api/classifieds", async (original) => ({
  ...(await original<typeof import("@/lib/api/classifieds")>()),
  getMyAds: vi.fn(),
}));

const card = (number: string, title: string, status: string) => ({ number, title, status, price: null, image: null, city: "Dubai", area: null, category: "electronics-souq", views: 0 });

/**
 * Browser test SOQ-17: My ads linked every title to the public ad page, but
 * only live ads have one (the API answers 404 otherwise), so a draft or an ad
 * in review opened "not found" (and its prefetch logged a 404).
 */
describe("account → my ads", () => {
  it("links only live ads to their public page", async () => {
    vi.mocked(api.getMyAds).mockResolvedValue({
      data: [card("CL-AE-1", "Live phone", "active"), card("CL-AE-2", "Draft phone", "draft"), card("CL-AE-3", "Phone in review", "pending_review")],
      meta: { last_page: 1 },
    } as unknown as Awaited<ReturnType<typeof api.getMyAds>>);
    renderWithProviders(<MyAdsView />);

    expect((await screen.findByRole("link", { name: "Live phone" })).getAttribute("href")).toContain("/classified/find/CL-AE-1");
    expect(screen.getByText("Draft phone").closest("a")).toBeNull();
    expect(screen.getByText("Phone in review").closest("a")).toBeNull();
  });
});
