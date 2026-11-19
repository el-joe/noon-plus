import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ReferencePaymentResult, travelPromotionState } from "@/features/checkout/reference-payment";
import { TripCard, TripSponsoredLabel } from "@/features/travel/shared";
import * as api from "@/lib/api/endpoints";
import type { PaymentStatus } from "@/lib/api/schemas";
import { travelPackage, travelPackageCard } from "@/lib/api/travel";
import { market, renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }), usePathname: () => "/uae-en" }));
vi.mock("@/lib/api/endpoints", () => ({ verifyPaymentByReference: vi.fn(), getPaymentByReference: vi.fn() }));

/** A TravelController@card payload from before D14 (no sponsored / label). */
const oldCard = {
  slug: "umrah-7",
  title: "Umrah 7 nights",
  agency: "Sunrise Travel",
  trip_type: "umrah",
  destination: "Makkah",
  nights: 7,
  from_price: { amount: "2500.00", currency: "AED" },
  status: "active",
};

const oldDetail = { ...oldCard, description: null, itinerary: [], inclusions: [], exclusions: [], cancellation_policy: [], departures: [] };

describe("travel schemas (D14)", () => {
  it("parses an old card as not sponsored", () => {
    const card = travelPackageCard.parse(oldCard);
    expect(card.sponsored).toBe(false);
    expect(card.label).toBeUndefined();
  });

  it("parses a promoted card with its server label", () => {
    const card = travelPackageCard.parse({ ...oldCard, sponsored: true, label: "Sponsored" });
    expect(card).toMatchObject({ sponsored: true, label: "Sponsored" });
    expect(travelPackageCard.parse({ ...oldCard, sponsored: false, label: null }).label).toBeNull();
  });

  it("defaults related to [] on an old detail and keeps the server order on a new one", () => {
    expect(travelPackage.parse(oldDetail).related).toEqual([]);
    const related = [
      { ...oldCard, slug: "promoted", sponsored: true, label: "مُموَّل" },
      { ...oldCard, slug: "organic", sponsored: false, label: null },
    ];
    const parsed = travelPackage.parse({ ...oldDetail, sponsored: true, label: "Sponsored", related });
    expect(parsed.sponsored).toBe(true);
    expect(parsed.related.map((r) => [r.slug, r.sponsored])).toEqual([
      ["promoted", true],
      ["organic", false],
    ]);
  });
});

describe("TripCard Sponsored label", () => {
  const card = (extra: Record<string, unknown> = {}) => travelPackageCard.parse({ ...oldCard, ...extra });

  it("shows no label on an organic trip", () => {
    render(<TripCard pkg={card()} market={market("uae-en")} />);
    expect(screen.queryByTestId("trip-sponsored")).toBeNull();
  });

  it("uses the server label when sponsored", () => {
    render(<TripCard pkg={card({ sponsored: true, label: "Promoted by Nawy" })} market={market("uae-en")} />);
    expect(screen.getByTestId("trip-sponsored").textContent).toBe("Promoted by Nawy");
  });

  it("falls back to the translated key when the label is empty", () => {
    render(<TripCard pkg={card({ sponsored: true, label: "" })} market={market("uae-en")} />);
    expect(screen.getByTestId("trip-sponsored").textContent).toBe("Sponsored");
  });

  it("falls back to the Arabic key in an Arabic market", () => {
    render(<TripSponsoredLabel pkg={{ sponsored: true, label: null }} market={market("uae-ar")} />);
    expect(screen.getByTestId("trip-sponsored").textContent).toBe("مُموَّل");
  });
});

describe("payment return for a trip promotion (kind=travel_promotion)", () => {
  const status = (paymentStatus: string, next = "none", promotion: { status: string; starts_at: string | null; ends_at: string | null } = { status: "active", starts_at: "2026-11-18T00:00:00Z", ends_at: "2026-11-25T00:00:00Z" }): PaymentStatus => ({
    payment: {
      reference: "pay-ref-1234567",
      method: "card",
      status: paymentStatus,
      amount: { amount: "150.00", currency: "AED" },
      refunded: null,
      expires_at: null,
      failure: null,
      next_action: { type: next },
    },
    purpose: "travel_promotion",
    subject: { number: "TP-AE-1", amount: { amount: "150.00", currency: "AED" }, ...promotion },
  });

  beforeEach(() => {
    vi.mocked(api.verifyPaymentByReference).mockReset();
    vi.mocked(api.getPaymentByReference).mockReset();
  });

  it("says the promotion is active until its end date, with no storefront link", async () => {
    vi.mocked(api.verifyPaymentByReference).mockResolvedValue({ data: status("captured"), meta: {} });
    const { container } = renderWithProviders(<ReferencePaymentResult reference="pay-ref-1234567" kind="travel_promotion" />);
    await waitFor(() => expect(screen.getByText("Payment received")).toBeTruthy());
    expect(container.textContent).toContain("Your promotion is now active until 25 Nov");
    expect(container.textContent).toContain("Trip promotion · Payment TP-AE-1");
    expect(container.querySelector("a")).toBeNull();
  });

  it("says the promotion is pending payment confirmation while the gateway has not confirmed", async () => {
    vi.mocked(api.verifyPaymentByReference).mockResolvedValue({ data: status("pending", "none", { status: "pending_payment", starts_at: null, ends_at: null }), meta: {} });
    const { container } = renderWithProviders(<ReferencePaymentResult reference="pay-ref-1234567" kind="travel_promotion" />);
    await waitFor(() => expect(screen.getByText("We're waiting for the payment provider")).toBeTruthy());
    expect(container.textContent).toContain("Your promotion is pending payment confirmation. It goes live as soon as the gateway confirms the payment.");
    expect(container.textContent).not.toContain("active until");
  });

  it("shows the Arabic state message", async () => {
    vi.mocked(api.verifyPaymentByReference).mockResolvedValue({ data: status("pending", "none", { status: "pending_payment", starts_at: null, ends_at: null }), meta: {} });
    const { container } = renderWithProviders(<ReferencePaymentResult reference="pay-ref-1234567" kind="travel_promotion" />, { market: "uae-ar" });
    await waitFor(() => expect(container.textContent).toContain("ترويجك بانتظار تأكيد الدفع."));
  });

  it("maps payment + promotion status to the state shown (active, queued, pending, not live, failed)", () => {
    const now = new Date("2026-11-20T00:00:00Z");
    const subject = (s: string, starts: string | null = null) => ({ number: "TP-AE-1", status: s, starts_at: starts, ends_at: null });
    expect(travelPromotionState("captured", subject("active", "2026-11-18T00:00:00Z"), now)).toBe("active");
    expect(travelPromotionState("captured", subject("active", "2026-11-25T00:00:00Z"), now)).toBe("queued");
    expect(travelPromotionState("captured", subject("pending_payment"), now)).toBe("pending");
    expect(travelPromotionState("authorized", subject("pending_payment"), now)).toBe("pending");
    expect(travelPromotionState("under_review", subject("pending_payment"), now)).toBe("pending");
    expect(travelPromotionState("captured", subject("cancelled"), now)).toBe("notLive");
    expect(travelPromotionState("failed", subject("pending_payment"), now)).toBe("failed");
    expect(travelPromotionState("expired", subject("cancelled"), now)).toBe("failed");
  });

  it("shows the failure without a retry link and points to the Travel Partner panel", async () => {
    vi.mocked(api.verifyPaymentByReference).mockResolvedValue({ data: status("failed", "retry"), meta: {} });
    const { container } = renderWithProviders(<ReferencePaymentResult reference="pay-ref-1234567" kind="travel_promotion" />);
    await waitFor(() => expect(screen.getByText("Your payment didn't go through")).toBeTruthy());
    expect(container.textContent).toContain("The payment did not go through, so your promotion is not active.");
    expect(container.textContent).toContain("Trip promotions are managed in the Travel Partner panel.");
    expect(container.querySelector("a")).toBeNull();
  });
});
