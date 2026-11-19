import { test, expect, expectClean, shoot, settle } from "../../support/fixtures";
import { CUSTOMER, PASSWORD } from "../../support/accounts";
import { mailMark, waitForMail } from "../../support/mail";
import { formOf, isSignedIn, rejectOptionalCookies, signInWithCode, signInWithPassword } from "../../support/storefront";

test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

/** D9 sign-in on the storefront: email code, password, forgot/reset by link; and the EN↔AR switch. */
test.describe("sign-in", () => {
  test("email-code sign-in (code from the mail log)", async ({ page, problems }) => {
    await signInWithCode(page, "uae-en", CUSTOMER.email);
    expect(await isSignedIn(page)).toBe(true);
    await page.goto("/uae-en/account");
    await settle(page);
    await expect(page.locator("body")).toContainText(CUSTOMER.email);
    await shoot(page, "flows", "auth-email-code-account");
    expectClean(problems);
  });

  test("email-code sign-in creates a new account in Arabic", async ({ page, problems }) => {
    const email = `e2e-new-${Date.now()}@nawy.test`;
    await signInWithCode(page, "uae-ar", email);
    expect(await isSignedIn(page)).toBe(true);
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    expectClean(problems);
  });

  test("password sign-in", async ({ page, problems }) => {
    await signInWithPassword(page, "uae-en", CUSTOMER.email, CUSTOMER.password);
    expect(await isSignedIn(page)).toBe(true);
    expectClean(problems);
  });

  test("wrong password is refused", async ({ page, problems }) => {
    await page.goto("/uae-en/login");
    await page.locator('input[type="email"]').fill(CUSTOMER.email);
    await page.getByRole("button", { name: "Sign in with password" }).click();
    await page.locator('input[type="password"]').fill("not-the-password");
    await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
    await expect(page.getByRole("alert").first()).toBeVisible();
    expect(await isSignedIn(page)).toBe(false);
    // The API answers 422 here, which the browser logs; that is the expected refusal.
    problems.console = problems.console.filter((line) => !/status of 422/.test(line));
    expectClean(problems);
  });

  test("forgot password: reset by the emailed link, then sign in", async ({ page, problems }) => {
    const email = "e2e-reset@nawy.test";
    await page.goto("/uae-en/login");
    await page.locator('input[type="email"]').fill(email);
    await page.getByRole("button", { name: "Sign in with password" }).click();
    await page.getByRole("button", { name: "Forgot password?" }).click();
    const mark = mailMark();
    await page.getByRole("button", { name: "Send reset email" }).click();
    await expect(page.locator("body")).toContainText(`If ${email} has a Nawy account`);
    const mail = await waitForMail(email, mark, /reset-password\?/);
    const link = /(http:\/\/localhost:3000\/uae-en\/reset-password\?[^\s)\]]+)/.exec(mail)![1].replace(/&amp;/g, "&");
    await page.goto(link);
    await page.locator('input[autocomplete="new-password"]').nth(0).fill(PASSWORD);
    await page.locator('input[autocomplete="new-password"]').nth(1).fill(PASSWORD);
    await formOf(page, 'input[autocomplete="new-password"]').locator('button[type="submit"]').click();
    // By link: the password is set and the shopper is signed straight in (resetDone → account).
    await expect(page).toHaveURL(/\/uae-en\/account/, { timeout: 20_000 });
    expect(await isSignedIn(page)).toBe(true);
    await shoot(page, "flows", "auth-reset-password-done");
    await page.context().clearCookies();
    await signInWithPassword(page, "uae-en", email, PASSWORD);
    expect(await isSignedIn(page)).toBe(true);
    expectClean(problems);
  });
});

test("locale switch EN ↔ AR keeps the page", async ({ page, problems }) => {
  await page.goto("/uae-en/deals");
  await settle(page);
  await page.getByRole("link", { name: "العربية" }).first().click();
  await expect(page).toHaveURL(/\/uae-ar\/deals/);
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  await settle(page);
  await shoot(page, "flows", "locale-switch-ar");
  await page.getByRole("link", { name: "English" }).first().click();
  await expect(page).toHaveURL(/\/uae-en\/deals/);
  await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
  expectClean(problems);
});
