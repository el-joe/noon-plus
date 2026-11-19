import type { Page } from "@playwright/test";
import { test, expect, expectClean, shoot, settle } from "../../support/fixtures";
import { CUSTOMER_STATE } from "../../support/accounts";
import { mailMark, waitForCode } from "../../support/mail";
import { rejectOptionalCookies } from "../../support/storefront";

test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

// 1×1 PNG: a stand-in logo / document upload.
const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64");

/** Fills the visible fields of one wizard step with valid test values. */
async function fillStep(page: Page, email: string) {
  const values: Record<string, string> = {
    name: "E2E Partner", email, phone: "+971501234567", password: "E2e-Passw0rd!", password_confirmation: "E2e-Passw0rd!",
    agency_name: "E2E Holidays", licence_number: "LIC-12345", city: "Dubai", area: "Marina", street: "E2E Street 1",
    store_name: "E2E Applicant Store", legal_name: "E2E Trading LLC", cr_number: "CR-12345", description: "Test application from the e2e run.",
    display_name: "E2E Creator", whatsapp_phone: "+971501234567",
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

test("partner application wizard (travel agency) with the email code", async ({ page, problems }) => {
  test.setTimeout(180_000);
  const email = `e2e-partner-${Date.now()}@nawy.test`;
  await page.goto("/uae-en/join/travel_agency");
  await settle(page);
  await shoot(page, "flows", "partner-01-account");

  const mark = mailMark();
  await fillStep(page, email);
  await page.getByRole("button", { name: "Save and continue" }).click();

  // Email code step.
  await expect(page.getByLabel("6-digit code")).toBeVisible({ timeout: 20_000 });
  await page.getByLabel("6-digit code").fill(await waitForCode(email, mark));
  await page.getByRole("button", { name: "Confirm email", exact: true }).click();
  // Confirmed: the stepper marks the email step completed and moves on.
  await expect(page.getByRole("button", { name: /Confirm email\s*Completed/ })).toBeVisible();
  await expect(page.locator('[aria-current="step"]')).not.toContainText("Confirm email");
  await settle(page, 4_000);

  // Remaining steps until the agreement / submit.
  for (let i = 0; i < 6; i++) {
    // The agreement is the last step (the stepper's current step says so before its form renders).
    if ((await page.locator('[aria-current="step"]').innerText()).includes("Agreement")) break;
    await expect(page.getByRole("button", { name: "Save and continue" })).toBeVisible({ timeout: 20_000 });
    await fillStep(page, email);
    for (const upload of await page.getByRole("button", { name: "Upload" }).all()) {
      const row = upload.locator("xpath=ancestor::*[.//input[@type='file']][1]");
      await row.locator('input[type="file"]').setInputFiles({ name: "document.png", mimeType: "image/png", buffer: PNG });
      await upload.click();
      await settle(page, 3_000);
    }
    await shoot(page, "flows", `partner-0${i + 2}-step`);
    const current = page.locator('[aria-current="step"]');
    const stepName = (await current.innerText()).split("\n").find((line) => /[A-Za-z]{3}/.test(line) && !/progress/i.test(line))!;
    await page.getByRole("button", { name: "Save and continue" }).click();
    await expect(current).not.toContainText(stepName, { timeout: 20_000 });
  }
  await expect(page.getByRole("button", { name: "Sign and submit" })).toBeVisible({ timeout: 20_000 });
  for (const box of await page.locator('main input[type="checkbox"]').all()) if (!(await box.isChecked())) await box.check();
  await page.getByLabel("Type your full name to sign").fill("E2E Partner");
  await page.getByRole("button", { name: "Sign and submit" }).click();
  await expect(page.locator("main")).toContainText("Application submitted", { timeout: 20_000 });
  await shoot(page, "flows", "partner-submitted");
  expectClean(problems);
});

test.describe("signed in", () => {
  test.use({ storageState: CUSTOMER_STATE });

  test("support ticket: open a ticket", async ({ page, problems }) => {
    await page.goto("/uae-en/account/support/new");
    await settle(page);
    const form = page.locator("main form");
    await form.getByLabel("Topic").selectOption({ index: 1 });
    await form.getByLabel("Subject").fill("E2E: where is my order?");
    await form.getByLabel("How can we help?").fill("Testing the support ticket flow end to end, please ignore.");
    await form.getByRole("button", { name: "Send ticket" }).click();
    await expect(page).toHaveURL(/\/account\/support\/TK-[A-Z]{2}-\d+/, { timeout: 20_000 });
    await settle(page);
    await expect(page.locator("main")).toContainText("E2E: where is my order?");
    await shoot(page, "flows", "support-ticket-opened");
    expectClean(problems);
  });

  test("classified viewing request on a property", async ({ page, problems }) => {
    const ads = JSON.parse(await (await page.request.get("http://localhost:8000/api/v1/uae-en/classifieds/ads?vertical=property&per_page=1")).text()).data;
    test.skip(!ads.length, "no property ad in uae");
    await page.goto(`/uae-en/property/${ads[0].number}`);
    await settle(page);
    const when = new Date(Date.now() + 3 * 86_400_000);
    when.setHours(11, 0, 0, 0);
    const local = `${when.getFullYear()}-${String(when.getMonth() + 1).padStart(2, "0")}-${String(when.getDate()).padStart(2, "0")}T11:00`;
    const form = page.locator("main form").filter({ has: page.locator('input[type="datetime-local"]') });
    await form.locator('input[type="datetime-local"]').fill(local);
    for (const [selector, value] of [['input[autocomplete="name"]', "E2E Customer"], ['input[type="email"]', "e2e-customer@nawy.test"], ['input[type="tel"]', "+971501234567"]] as const) {
      const input = form.locator(selector).first();
      if ((await input.count()) && !(await input.inputValue())) await input.fill(value);
    }
    const view = page.getByRole("radio").first();
    if (await view.count()) await view.check();
    await page.getByRole("button", { name: /Send viewing request|Send reserve request/ }).click();
    // D12: a record only — the customer sees "Request sent" and a plain list, never a status.
    await expect(page.locator("main")).toContainText("Request sent");
    await shoot(page, "flows", "classified-viewing-request");
    await page.goto("/uae-en/account/inquiries?tab=requests");
    await settle(page);
    const first = page.locator("main [data-request]").first();
    await expect(first).toContainText(ads[0].title);
    await expect(first).toContainText("Preferred time:");
    await expect(first).not.toContainText(/Requested|Confirmed|Scheduled|Completed|Declined|Cancelled/);
    await expect(page.locator("main").getByRole("button", { name: /^(Confirm|Reject|Cancel request)$/ })).toHaveCount(0);
    expectClean(problems);
  });
});

test("compare two products", async ({ page, problems }) => {
  const products = JSON.parse(await (await page.request.get("http://localhost:8000/api/v1/uae-en/products?per_page=2")).text()).data as { slug: string; title: string }[];
  test.skip(products.length < 2, "fewer than 2 products in uae");
  for (const p of products) {
    await page.goto(`/uae-en/products/${p.slug}`);
    await settle(page);
    await page.getByRole("button", { name: "Add to compare" }).click();
    await expect(page.getByRole("button", { name: "Comparing" })).toBeVisible();
  }
  await page.getByRole("link", { name: "Compare now" }).click();
  await expect(page).toHaveURL(/\/uae-en\/compare\?slugs=/);
  await settle(page);
  for (const p of products) await expect(page.locator("main")).toContainText(p.title);
  await shoot(page, "flows", "compare");
  expectClean(problems);
});
