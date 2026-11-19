import { fireEvent, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { EmailPreferencesView, UnsubscribeView } from "@/features/account-extra/email-preferences";
import * as api from "@/lib/api/email-preferences";
import { renderWithProviders } from "../helpers/render";

vi.mock("@/lib/api/email-preferences", async (original) => ({
  ...(await original<typeof import("@/lib/api/email-preferences")>()),
  getEmailPreferences: vi.fn(),
  saveEmailPreferences: vi.fn(),
  getUnsubscribe: vi.fn(),
  unsubscribe: vi.fn(),
  resubscribe: vi.fn(),
}));

const categories: api.EmailCategory[] = [
  { topic: "transactional", label: "Orders, payments and account", description: "Always sent.", enabled: true, locked: true },
  { topic: "reminders", label: "Reminders and deadlines", description: "Expiring things.", enabled: true, locked: false },
  { topic: "cart_reminders", label: "Cart reminders", description: "Opt-in.", enabled: false, locked: false },
];

afterEach(() => {
  vi.mocked(api.getEmailPreferences).mockReset();
  vi.mocked(api.saveEmailPreferences).mockReset();
  vi.mocked(api.getUnsubscribe).mockReset();
  vi.mocked(api.unsubscribe).mockReset();
  vi.mocked(api.resubscribe).mockReset();
});

/** Email system: Account → Notifications → Email settings and the one-click unsubscribe landing. */
describe("account → email settings", () => {
  it("shows locked transactional mail and saves only the categories that can be turned off", async () => {
    vi.mocked(api.getEmailPreferences).mockResolvedValue({ data: { categories }, meta: {} });
    vi.mocked(api.saveEmailPreferences).mockResolvedValue({ data: { categories: categories.map((c) => (c.topic === "cart_reminders" ? { ...c, enabled: true } : c)) }, meta: {} });
    renderWithProviders(<EmailPreferencesView />);

    expect(await screen.findAllByTestId("email-category")).toHaveLength(3);
    expect(screen.getByText("Always on")).toBeTruthy();
    expect(screen.queryByRole("checkbox", { name: "Orders, payments and account" })).toBeNull();

    fireEvent.click(screen.getByRole("checkbox", { name: "Cart reminders" }));
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));

    await waitFor(() =>
      expect(api.saveEmailPreferences).toHaveBeenCalledWith("uae-en", [
        { topic: "reminders", enabled: true },
        { topic: "cart_reminders", enabled: true },
      ]),
    );
    expect(await screen.findByText("Your email settings are saved.")).toBeTruthy();
  });

  it("renders in Arabic", async () => {
    vi.mocked(api.getEmailPreferences).mockResolvedValue({ data: { categories }, meta: {} });
    renderWithProviders(<EmailPreferencesView />, { market: "uae-ar" });
    expect(await screen.findByText("إعدادات البريد الإلكتروني")).toBeTruthy();
    expect(await screen.findByText("مفعّل دائماً")).toBeTruthy();
  });
});

describe("unsubscribe landing", () => {
  it("unsubscribes with the signed token and can subscribe again", async () => {
    vi.mocked(api.getUnsubscribe).mockResolvedValue({ data: { topic: "reminders", label: "Reminders and deadlines", email: "s***@e******.com", subscribed: true }, meta: {} });
    vi.mocked(api.unsubscribe).mockResolvedValue({ data: { topic: "reminders", label: "Reminders and deadlines", email: "s***@e******.com", subscribed: false }, meta: {} });
    vi.mocked(api.resubscribe).mockResolvedValue({ data: { topic: "reminders", label: "Reminders and deadlines", email: "s***@e******.com", subscribed: true }, meta: {} });
    renderWithProviders(<UnsubscribeView token="abc.def" />);

    expect(await screen.findByText("Stop receiving “Reminders and deadlines” emails?")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Unsubscribe" }));
    await waitFor(() => expect(api.unsubscribe).toHaveBeenCalledWith("abc.def"));
    expect(await screen.findByText("You are unsubscribed")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "Subscribe again" }));
    await waitFor(() => expect(api.resubscribe).toHaveBeenCalledWith("abc.def"));
  });

  it("explains an invalid or missing token", async () => {
    renderWithProviders(<UnsubscribeView token={null} />);
    expect(screen.getByText("Link not valid")).toBeTruthy();
    expect(api.getUnsubscribe).not.toHaveBeenCalled();
  });
});
