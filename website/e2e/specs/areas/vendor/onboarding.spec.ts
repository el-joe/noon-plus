import type { Page } from "@playwright/test";
import { test, expect, expectClean } from "../../../support/fixtures";
import { panelUrl } from "../../../support/accounts";
import { mailMark, waitForCode, waitForMail } from "../../../support/mail";
import { rejectOptionalCookies } from "../../../support/storefront";
import { PNG, panelContext, sc, shooter, signedInAs, tinkerIn, vendorFixtures } from "./support/vendor";

/**
 * Becoming a seller: the /join partner application with an account
 * manager's ?ref= link, Nawy approving it, the first Seller Center sign-in,
 * and the Seller Agreement gate (signing). Report:
 * vendor-panel-catalog-and-orders (VEN-13, VEN-14).
 */
const shot = shooter("vendor-panel-catalog-and-orders");

/** Fills the visible fields of one wizard step with test values. */
async function fillStep(page: Page, email: string, run: string) {
  const values: Record<string, string> = {
    name: `E2E Applicant ${run}`, email, phone: "+971501234567", password: "E2e-Passw0rd!", password_confirmation: "E2e-Passw0rd!",
    store_name: `E2E Applicant Store ${run}`, slug: `e2e-applicant-${run}`, legal_name: `E2E Trading ${run} LLC`, cr_number: `CR-${run}`, trn: "100000000000003", vat_number: "100000000000003",
    city: "Dubai", area: "Al Quoz", street: "E2E Street 1", building: "Unit 4", description: "Electronics seller, browser-test application.",
    iban: "AE070331234567890123456", bank_name: "E2E Bank", account_name: `E2E Trading ${run}`,
  };
  for (const [name, value] of Object.entries(values)) {
    const input = page.locator(`main form [name="${name}"]`);
    if ((await input.count()) && (await input.first().isVisible()) && !(await input.first().inputValue({ timeout: 2_000 }).catch(() => "skip"))) await input.first().fill(value);
  }
  for (const select of await page.locator("main form select[name]").all()) {
    if ((await select.isVisible()) && !(await select.inputValue())) await select.selectOption({ index: 1 });
  }
  for (const file of await page.locator('main form input[type="file"][name]').all()) {
    await file.setInputFiles({ name: "logo.png", mimeType: "image/png", buffer: PNG });
  }
}

test("VEN-13 /join?ref= seller application → Nawy approves → first Seller Center sign-in", async ({ page, browser, problems }) => {
  test.setTimeout(300_000);
  const fx = vendorFixtures();
  const run = `${fx.run}j`;
  const email = `e2e-ven-apply-${run}@nawy.test`;
  await rejectOptionalCookies(page);

  // The account manager's link: /join?ref=AM{id}-{check}; the chooser keeps it for the wizard.
  await page.goto(`/uae-en/join?ref=${fx.acquirer.code}`);
  await page.waitForLoadState("networkidle").catch(() => undefined);
  await shot(page, "VEN-13", 1);
  expect(await page.evaluate(() => localStorage.getItem("nawy_join_ref"))).toContain(fx.acquirer.code);
  await page.goto("/uae-en/join/vendor");
  await page.waitForLoadState("networkidle").catch(() => undefined);
  const mark = mailMark();
  await fillStep(page, email, run);
  await shot(page, "VEN-13", 2);
  await page.getByRole("button", { name: "Save and continue" }).click();
  await expect(page.getByLabel("6-digit code")).toBeVisible({ timeout: 20_000 });
  await page.getByLabel("6-digit code").fill(await waitForCode(email, mark));
  await page.getByRole("button", { name: "Confirm email", exact: true }).click();
  await expect(page.getByRole("button", { name: /Confirm email\s*Completed/ })).toBeVisible();

  for (let i = 0; i < 8; i++) {
    await page.waitForTimeout(500);
    if ((await page.locator('[aria-current="step"]').innerText()).includes("Agreement")) break;
    await expect(page.getByRole("button", { name: "Save and continue" })).toBeVisible({ timeout: 20_000 });
    await fillStep(page, email, run);
    // Documents step: one upload form per required document; each disappears once its file is in.
    const uploadForms = page.locator("main form").filter({ has: page.locator('input[type="file"]:not([name])') });
    for (let n = await uploadForms.count(); n > 0; n--) {
      const form = uploadForms.first();
      await form.locator('input[type="file"]').setInputFiles({ name: "document.png", mimeType: "image/png", buffer: PNG });
      const expiry = form.locator('input[type="date"]');
      if (await expiry.count()) await expiry.fill(new Date(Date.now() + 400 * 86_400_000).toISOString().slice(0, 10));
      await form.locator('button[type="submit"]').click();
      await expect(uploadForms).toHaveCount(n - 1, { timeout: 20_000 });
    }
    await shot(page, "VEN-13", 3 + i);
    const current = page.locator('[aria-current="step"]');
    const stepName = (await current.innerText()).split("\n").find((line) => /[A-Za-z]{3}/.test(line) && !/progress/i.test(line))!;
    await page.getByRole("button", { name: "Save and continue" }).click();
    await expect(current).not.toContainText(stepName, { timeout: 20_000 });
  }
  await expect(page.getByRole("button", { name: "Sign and submit" })).toBeVisible({ timeout: 20_000 });
  for (const box of await page.locator('main input[type="checkbox"]').all()) if (!(await box.isChecked())) await box.check();
  await page.getByLabel("Type your full name to sign").fill(`E2E Applicant ${run}`);
  await page.getByRole("button", { name: "Sign and submit" }).click();
  await expect(page.locator("main")).toContainText("Application submitted", { timeout: 20_000 });
  await shot(page, "VEN-13", 12);

  // The referral was recorded on the application (FR-FIN-6).
  const app = JSON.parse(tinkerIn("uae", `echo json_encode(App\\Domains\\Onboarding\\Models\\PartnerApplication::query()->where('email', '${email}')->latest('id')->first(['id', 'number', 'status', 'acquired_by_user_id']));`).split("\n").pop()!) as { id: number; number: string; status: string; acquired_by_user_id: number | null };
  expect(app.acquired_by_user_id, "acquirer from ?ref=").toBe(fx.acquirer.user_id);

  // Nawy reviews and approves it (admin panel, only so the seller flow can continue).
  const admin = await panelContext(browser, "admin");
  await admin.page.goto(panelUrl("admin", `applications/${app.id}`));
  const start = admin.page.getByRole("button", { name: "Start review" });
  if (await start.count()) await start.click();
  for (const approveDoc of await admin.page.locator('form[action*="/documents/"] button[value="approve"]').all()) await approveDoc.click().catch(() => undefined);
  const approveMark = mailMark();
  await admin.page.locator("form[data-decision-form]").getByRole("button", { name: "Approve" }).click();
  await expect(admin.page.locator("main")).toContainText(/Approved/i);
  await shot(admin.page, "VEN-13", 13);
  await admin.context.close();

  // The new seller is told and signs in to Seller Center.
  await waitForMail(email, approveMark, /./).catch(() => undefined);
  const { context, page: seller } = await signedInAs(browser, email);
  await expect(seller.locator("#panel-sidebar")).toContainText("Products");
  await shot(seller, "VEN-13", 14);
  await context.close();
  expectClean(problems);
});

test("VEN-14 Seller Agreement gate: a store that has not signed is sent to Contracts, signs, then works", async ({ browser, problems }) => {
  test.setTimeout(150_000);
  const fx = vendorFixtures();
  const { context, page } = await signedInAs(browser, fx.gate.email);
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(sc("products"));
  await expect(page).toHaveURL(/\/contracts/);
  await expect(page.locator("main")).toContainText("Sign the updated Seller Agreement to continue.");
  await shot(page, "VEN-14", 1);
  await page.goto(sc(`contracts/${fx.gate.version_id}`));
  await shot(page, "VEN-14", 2);
  const sign = page.locator(`form[action$="/contracts/${fx.gate.version_id}/sign"]`);
  // Not accepted → refused.
  await sign.locator('[name="full_name"]').fill(`E2E Gate Seller ${fx.run}`);
  await sign.getByRole("button", { name: "Sign contract" }).click();
  await expect(page.locator("main [role='alert']").first()).toBeVisible();
  await page.locator(`form[action$="/contracts/${fx.gate.version_id}/sign"] [name="full_name"]`).fill(`E2E Gate Seller ${fx.run}`);
  await page.locator(`form[action$="/contracts/${fx.gate.version_id}/sign"]`).getByLabel("I have read and accept this contract").check();
  await page.locator(`form[action$="/contracts/${fx.gate.version_id}/sign"]`).getByRole("button", { name: "Sign contract" }).click();
  await expect(page.locator("main")).toContainText(/Signed|signed/);
  await shot(page, "VEN-14", 3);
  await page.goto(sc("products"));
  await expect(page).toHaveURL(/\/products/);
  await shot(page, "VEN-14", 4);
  // The signed copy downloads.
  await page.goto(sc("contracts"));
  const download = page.locator('main a[href*="/contracts/signatures/"]').first();
  if (await download.count()) {
    const res = await page.evaluate(async (u) => (await fetch(u)).status, (await download.getAttribute("href"))!);
    expect(res).toBe(200);
  }
  await shot(page, "VEN-14", 5);
  await context.close();
  expectClean(problems);
});

