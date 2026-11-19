import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SavedCardsView } from "@/features/account/saved-cards";
import * as api from "@/lib/api/saved-cards";
import { renderWithProviders } from "../helpers/render";

vi.mock("@/lib/api/saved-cards", async (original) => ({
  ...(await original<typeof import("@/lib/api/saved-cards")>()),
  getSavedCards: vi.fn(),
  makeDefaultCard: vi.fn(),
  deleteSavedCard: vi.fn(),
}));

type Card = api.SavedCard;
const visa: Card = { id: 1, gateway: "fake", brand: "visa", last4: "4242", exp_month: 4, exp_year: 2030, expired: false, is_default: true };
const master: Card = { id: 2, gateway: "fake", brand: "mastercard", last4: "5100", exp_month: 1, exp_year: 2024, expired: true, is_default: false };
const envelope = (data: Card[]) => ({ data, meta: {} });

afterEach(() => {
  vi.restoreAllMocks();
  vi.mocked(api.getSavedCards).mockReset();
  vi.mocked(api.makeDefaultCard).mockReset();
  vi.mocked(api.deleteSavedCard).mockReset();
});

/**
 * QA-C05: the website had no saved-cards screen (the API `me/cards` existed).
 * Account → Saved cards lists tokens as brand / last 4 / expiry with the
 * default badge, sets the default and removes a card after a confirm.
 */
describe("account → saved cards", () => {
  it("lists brand, last 4, expiry and the default / expired badges — never a card number", async () => {
    vi.mocked(api.getSavedCards).mockResolvedValue(envelope([visa, master]));
    const { container } = renderWithProviders(<SavedCardsView />);

    const items = await screen.findAllByTestId("saved-card");
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByText("Visa")).toBeTruthy();
    expect(within(items[0]).getByText("•••• 4242")).toBeTruthy();
    expect(items[0].textContent).toContain("04/30");
    expect(within(items[0]).getByText("Default")).toBeTruthy();
    expect(within(items[0]).queryByRole("button", { name: "Set as default" })).toBeNull();
    expect(within(items[1]).getByText("Mastercard")).toBeTruthy();
    expect(within(items[1]).getByText("Expired")).toBeTruthy();
    expect(container.textContent).not.toMatch(/\d{13,19}/);
  });

  it("shows the empty state", async () => {
    vi.mocked(api.getSavedCards).mockResolvedValue(envelope([]));
    renderWithProviders(<SavedCardsView />);
    expect(await screen.findByText("You have no saved cards.")).toBeTruthy();
  });

  it("sets a card as default and reloads the list", async () => {
    vi.mocked(api.getSavedCards).mockResolvedValueOnce(envelope([visa, master])).mockResolvedValue(envelope([{ ...visa, is_default: false }, { ...master, is_default: true }]));
    vi.mocked(api.makeDefaultCard).mockResolvedValue({ data: { ...master, is_default: true }, meta: {} });
    renderWithProviders(<SavedCardsView />);

    fireEvent.click(await screen.findByRole("button", { name: "Set as default" }));
    await waitFor(() => expect(api.makeDefaultCard).toHaveBeenCalledWith("uae-en", 2));
    await waitFor(() => expect(api.getSavedCards).toHaveBeenCalledTimes(2));
  });

  it("removes a card only after the customer confirms", async () => {
    vi.mocked(api.getSavedCards).mockResolvedValue(envelope([visa]));
    vi.mocked(api.deleteSavedCard).mockResolvedValue({ data: { removed: true }, meta: {} });
    const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
    renderWithProviders(<SavedCardsView />);

    const remove = await screen.findByRole("button", { name: "Remove Visa ending 4242" });
    fireEvent.click(remove);
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining("4242"));
    expect(api.deleteSavedCard).not.toHaveBeenCalled();

    fireEvent.click(remove);
    await waitFor(() => expect(api.deleteSavedCard).toHaveBeenCalledWith("uae-en", 1));
  });

  it("renders in Arabic", async () => {
    vi.mocked(api.getSavedCards).mockResolvedValue(envelope([visa]));
    renderWithProviders(<SavedCardsView />, { market: "uae-ar" });
    expect(await screen.findByRole("heading", { name: "البطاقات المحفوظة" })).toBeTruthy();
    expect(await screen.findByText("الافتراضية")).toBeTruthy();
    expect(screen.getByText("•••• 4242").getAttribute("dir")).toBe("ltr");
  });
});

describe("saved cards API client", () => {
  const respond = (body: unknown) => vi.fn(async () => new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } }));

  it("calls me/cards, me/cards/{id}/default and DELETE me/cards/{id}", async () => {
    const { getSavedCards, makeDefaultCard, deleteSavedCard } = await vi.importActual<typeof import("@/lib/api/saved-cards")>("@/lib/api/saved-cards");
    const fetchMock = respond({ data: [visa] });
    vi.stubGlobal("fetch", fetchMock);
    const list = await getSavedCards("uae-en");
    expect(list.data[0].last4).toBe("4242");
    expect(String((fetchMock.mock.calls[0] as unknown as [string])[0])).toMatch(/\/me\/cards$/);

    const defaultMock = respond({ data: { ...master, is_default: true } });
    vi.stubGlobal("fetch", defaultMock);
    await makeDefaultCard("uae-en", 2);
    const [url, init] = defaultMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toMatch(/\/me\/cards\/2\/default$/);
    expect(init.method).toBe("POST");

    const deleteMock = respond({ data: { removed: true } });
    vi.stubGlobal("fetch", deleteMock);
    await deleteSavedCard("uae-en", 2);
    const [delUrl, delInit] = deleteMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(delUrl).toMatch(/\/me\/cards\/2$/);
    expect(delInit.method).toBe("DELETE");
    vi.unstubAllGlobals();
  });

  it("formats brand names and expiry", () => {
    expect(api.cardBrandName("visa")).toBe("Visa");
    expect(api.cardBrandName("amex")).toBe("American Express");
    expect(api.cardBrandName("rupay")).toBe("Rupay");
    expect(api.cardBrandName(null)).toBeNull();
    expect(api.cardExpiry({ exp_month: 4, exp_year: 2030 })).toBe("04/30");
    expect(api.cardExpiry({ exp_month: null, exp_year: 2030 })).toBeNull();
  });
});
