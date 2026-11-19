import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SignInForm } from "@/features/auth/sign-in-form";
import * as api from "@/lib/api/endpoints";
import type { Me } from "@/lib/api/schemas";
import { finishSignIn } from "@/features/auth/sign-in-helpers";
import { renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams("next=/uae-en/account/orders"), useRouter: () => ({ replace: vi.fn() }) }));
vi.mock("@/lib/api/endpoints", () => ({ sendPhoneCode: vi.fn(), verifyPhoneCode: vi.fn(), sendEmailCode: vi.fn() }));
vi.mock("@/features/auth/sign-in-helpers", () => ({
  deviceName: () => "Web · test",
  safeNext: (next: string | null, market: string) => next ?? `/${market}/account`,
  finishSignIn: vi.fn(async () => undefined),
}));
vi.mock("@/features/auth/social-buttons", () => ({ SocialButtons: () => null, useSocialErrorText: () => (code: string) => code }));

const legacyUser = { id: 7, name: "Old Customer", email: "phone-971501234567@legacy.nawy.invalid", requires_email: true } as Me;

beforeEach(() => {
  vi.mocked(api.sendPhoneCode).mockReset().mockResolvedValue({ data: { sent: true }, meta: {} });
  vi.mocked(api.verifyPhoneCode).mockReset().mockResolvedValue({ data: { user: legacyUser }, meta: {} });
  vi.mocked(finishSignIn).mockClear();
});

describe("SignInForm — old phone-only accounts", () => {
  it("signs in with an SMS code, then sends the account to add its email", async () => {
    const { container } = renderWithProviders(<SignInForm />);

    fireEvent.click(screen.getByRole("button", { name: /only a phone number/i }));
    fireEvent.change(container.querySelector('input[type="tel"]')!, { target: { value: "050 123 4567" } });
    fireEvent.submit(container.querySelector("form")!);
    await waitFor(() => expect(api.sendPhoneCode).toHaveBeenCalledWith("uae-en", "050 123 4567"));

    const code = await waitFor(() => container.querySelector('input[autocomplete="one-time-code"]') as HTMLInputElement);
    fireEvent.change(code, { target: { value: "123456" } });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(finishSignIn).toHaveBeenCalled());
    expect(api.verifyPhoneCode).toHaveBeenCalledWith("uae-en", "050 123 4567", "123456", "Web · test");
    expect(vi.mocked(finishSignIn).mock.calls[0][3]).toBe(`/uae-en/auth/add-email?next=${encodeURIComponent("/uae-en/account/orders")}`);
  });
});
