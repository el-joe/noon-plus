import { fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { InquiriesView } from "@/features/classifieds/account";
import * as api from "@/lib/api/classifieds";
import { renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }),
  useSearchParams: () => new URLSearchParams("tab=requests"),
}));
vi.mock("@/lib/api/classifieds", async (original) => ({
  ...(await original<typeof import("@/lib/api/classifieds")>()),
  getMyViewingRequests: vi.fn(),
  getReceivedViewingRequests: vi.fn(),
}));

const ad = { number: "C-1001", title: "2BR Marina Gate", price: null, image: null, city: "Dubai", area: null, status: "active", category: "Property" };

beforeEach(() => {
  vi.mocked(api.getMyViewingRequests).mockReset();
  vi.mocked(api.getReceivedViewingRequests).mockReset();
});

/** Owner decision D12: viewing / reserve requests are records only. */
describe("account → inquiries → requests", () => {
  it("lists the customer's own requests as plain records: ad, date, preferred time, message — no status", async () => {
    vi.mocked(api.getMyViewingRequests).mockResolvedValue({
      data: [{ id: 7, type: "viewing", preferred_at: "2026-11-20T10:00:00+04:00", note: "After 6 pm please", created_at: "2026-11-18T08:00:00+00:00", ad }],
      meta: { last_page: 1 },
    });
    renderWithProviders(<InquiriesView />);

    expect(await screen.findByRole("link", { name: "2BR Marina Gate" })).toBeTruthy();
    expect(screen.getByText(/^Sent /)).toBeTruthy();
    expect(screen.getByText(/^Preferred time: /)).toBeTruthy();
    expect(screen.getByText("Message: After 6 pm please")).toBeTruthy();
    for (const status of ["Requested", "Confirmed", "Scheduled", "Completed", "Declined", "Cancelled"]) expect(screen.queryByText(status)).toBeNull();
    expect(screen.queryByRole("button", { name: /confirm|cancel|reject/i })).toBeNull();
    expect(api.getReceivedViewingRequests).not.toHaveBeenCalled();
  });

  it("shows the requests received on the user's own ads with the customer's contact details", async () => {
    vi.mocked(api.getMyViewingRequests).mockResolvedValue({ data: [], meta: { last_page: 1 } });
    vi.mocked(api.getReceivedViewingRequests).mockResolvedValue({
      data: [{ id: 9, type: "reserve", preferred_at: "2026-11-21T18:00:00+04:00", note: null, created_at: "2026-11-18T09:00:00+00:00", ad, contact: { name: "Sara M.", email: "sara@example.com", phone: "+971501234567" } }],
      meta: { last_page: 1 },
    });
    renderWithProviders(<InquiriesView />);

    expect(await screen.findByText("You have not sent any viewing or reserve requests yet.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "On my ads" }));
    expect(await screen.findByText("Sara M.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "sara@example.com" }).getAttribute("href")).toBe("mailto:sara@example.com");
    expect(screen.getByRole("link", { name: "+971501234567" }).getAttribute("href")).toBe("tel:+971501234567");
    expect(screen.queryByRole("button", { name: /confirm|reject|schedule/i })).toBeNull();
  });

  it("parses a request record and drops any legacy status the API might still send", () => {
    const parsed = api.viewingRequest.parse({ id: 1, type: "viewing", status: "confirmed", scheduled_at: null, preferred_at: null, created_at: null, ad: null });
    expect(parsed).not.toHaveProperty("status");
    expect(parsed).not.toHaveProperty("scheduled_at");
  });
});
