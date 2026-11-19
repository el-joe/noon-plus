import { test as setup, expect } from "@playwright/test";
import { PANELS, PASSWORD, panelState, panelUrl, type PanelKey } from "../support/accounts";
import { mailMark, waitForCode } from "../support/mail";

/**
 * Signs in to each of the 6 Blade panels with the seeded e2e user: password,
 * then the emailed two-step code read from the Laravel mail log. The session
 * is saved per panel host for panels.spec.ts.
 */
for (const panel of Object.keys(PANELS) as PanelKey[]) {
  setup(`sign in to ${panel} with email 2FA`, async ({ page }) => {
    const { email } = PANELS[panel];
    await page.goto(panelUrl(panel, "login"));
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(PASSWORD);
    const mark = mailMark();
    await page.locator('form button[type="submit"]').first().click();
    await expect(page).toHaveURL(/two-factor/);
    const code = await waitForCode(email, mark);
    await page.locator('input[name="code"]').fill(code);
    await page.locator('form button[type="submit"]').first().click();
    await expect(page).not.toHaveURL(/two-factor|login/);
    await expect(page.locator("#panel-sidebar")).toBeAttached();
    await page.context().storageState({ path: panelState(panel) });
  });
}
