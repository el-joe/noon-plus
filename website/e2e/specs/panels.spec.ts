import type { Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test, expect, expectClean, shoot } from "../support/fixtures";
import { CUSTOMER_STATE, PANELS, panelState, panelUrl, type PanelKey } from "../support/accounts";
import { GENERATED } from "../support/global-setup";
import type { Fixtures } from "../support/routes";

const fixtures = (): Fixtures => JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8")) as Fixtures;

async function expectPanelPage(page: Page, url: string) {
  const res = await page.goto(url, { waitUntil: "load" });
  expect.soft(res?.status(), `HTTP status of ${url}`).toBe(200);
  const text = await page.locator("body").innerText();
  expect.soft(text, `500 text on ${url}`).not.toMatch(/Server Error|Internal Server Error|Whoops|ErrorException|SQLSTATE/);
  expect.soft(text, `raw lang keys on ${url}`).not.toMatch(/\b(?:validation|passwords|auth|pagination|panels?|messages)\.[a-z_]+\.?[a-z_]*\b/);
}

/** Pages that must be real sidebar links (built, not "Soon"), per panel. */
const REQUIRED_SIDEBAR_PATHS: Partial<Record<PanelKey, string[]>> = {
  partner: ["/coupons", "/earnings"],
  marketer: ["/products"],
};

for (const panel of Object.keys(PANELS) as PanelKey[]) {
  test.describe(`${panel} panel`, () => {
    test.use({ storageState: panelState(panel) });

    test(`every sidebar link of ${panel}`, async ({ page, problems }) => {
      test.setTimeout(15 * 60_000);
      await expectPanelPage(page, panelUrl(panel));
      const origin = new URL(panelUrl(panel)).origin;
      const links = await page.$$eval("#panel-sidebar a[href]", (as) => [...new Set(as.map((a) => (a as HTMLAnchorElement).href.split("#")[0]))]);
      const internal = links.filter((h) => h.startsWith(origin));
      expect(internal.length, "sidebar links").toBeGreaterThan(2);
      test.info().annotations.push({ type: "sidebar links", description: String(internal.length) });
      const paths = internal.map((h) => new URL(h).pathname.replace(/\/$/, ""));
      for (const required of REQUIRED_SIDEBAR_PATHS[panel] ?? []) {
        expect(paths, `sidebar link ${required} on ${panel}`).toContain(required);
      }
      for (const link of internal) {
        await expectPanelPage(page, link);
        const name = new URL(link).pathname.replace(/^\/|\/$/g, "").replace(/\//g, "__") || "dashboard";
        await shoot(page, `panels/${panel}`, name);
      }
      expectClean(problems);
    });
  });
}

test.describe("panel key actions", () => {
  test.describe("admin", () => {
    test.use({ storageState: panelState("admin") });
    test("approves a listing", async ({ page, problems }) => {
      const id = fixtures().pending_listing_id;
      await page.goto(panelUrl("admin", "approvals"));
      const form = page.locator(`form[action$="/approvals/listings/${id}/approve"]`);
      await expect(form).toHaveCount(1);
      await form.locator('button[type="submit"]').click();
      await expect(page.locator(`form[action$="/approvals/listings/${id}/approve"]`)).toHaveCount(0);
      await shoot(page, "panels/actions", "admin-approve-listing");
      expectClean(problems);
    });
  });

  test.describe("seller", () => {
    test.use({ storageState: panelState("partner") });
    test("confirms a sub-order", async ({ page, problems }) => {
      const { placed_sub_order: so } = fixtures();
      await page.goto(panelUrl("partner", `orders/${so.id}`));
      await expect(page.getByText(so.number).first()).toBeVisible();
      const advance = page.locator(`form[action$="/orders/${so.id}/advance"]`).first();
      await advance.locator('button[type="submit"]').first().click();
      await expect(page.locator("body")).toContainText(/Confirmed/i);
      await shoot(page, "panels/actions", "seller-confirm-sub-order");
      expectClean(problems);
    });
  });

  test.describe("carrier then delivery", () => {
    test("carrier assigns a driver; the driver confirms with the code match", async ({ browser, problems }) => {
      const { shipment, packed_sub_order: parcel } = fixtures();
      const carrier = await browser.newContext({ storageState: panelState("carrier") });
      const cpage = await carrier.newPage();
      cpage.on("console", (m) => m.type() === "error" && problems.console.push(`${cpage.url()} :: ${m.text()}`));
      await cpage.goto(panelUrl("carrier", "pickups"));
      const assign = cpage.locator(`form[action$="/pickups/${shipment.id}/assign"]`).filter({ has: cpage.locator('select[name="agent_id"]') });
      await expect(assign).toHaveCount(1);
      await assign.locator('select[name="agent_id"]').selectOption({ label: "E2E Driver" });
      await assign.locator('button[type="submit"]').click();
      await expect(cpage.locator(`form[action$="/pickups/${shipment.id}/assign"]`)).toHaveCount(0);
      await shoot(cpage, "panels/actions", "carrier-assign-driver");
      await carrier.close();

      const delivery = await browser.newContext({ storageState: panelState("delivery") });
      const dpage = await delivery.newPage();
      dpage.on("console", (m) => m.type() === "error" && problems.console.push(`${dpage.url()} :: ${m.text()}`));
      await dpage.goto(panelUrl("delivery", "assignments"));
      await dpage.getByRole("link", { name: new RegExp(shipment.number.replace(/^SH-/, "").replace(/-1$/, ""), "i") }).first().click();
      for (const label of [/^Accept$/, /Picked up/, /I have arrived/]) {
        await dpage.getByRole("button", { name: label }).click();
        await expect(dpage.locator("body")).toContainText(/Saved/);
      }
      const confirm = dpage.locator("form[data-confirm-form]");
      // ADR 15: the agent sees the 4-digit code and only taps "Codes match"; nothing is typed.
      await expect(dpage.locator("body")).toContainText(/Delivery code[\s\S]*?\d\s*\d\s*\d\s*\d/);
      await expect(confirm.locator('input:not([type="hidden"]):not([type="file"])')).toHaveCount(0);
      const driverCode = /Delivery code[\s\S]*?(\d)\s*(\d)\s*(\d)\s*(\d)/.exec(await dpage.locator("body").innerText())!.slice(1).join("");

      // The customer's order page shows the same code once the driver has started (ADR 15).
      const shopper = await browser.newContext({ storageState: CUSTOMER_STATE, baseURL: "http://localhost:3000" });
      const spage = await shopper.newPage();
      await spage.goto(`/uae-en/account/orders/${parcel.order}`);
      await expect(spage.locator("main")).toContainText(/Delivery code/i);
      await expect.poll(async () => (await spage.locator("main").innerText()).replace(/\s+/g, "").toLowerCase()).toContain(`deliverycode${driverCode}`);
      await shoot(spage, "flows", "order-detail-delivery-code");
      await shopper.close();
      const canvas = confirm.locator("canvas").first();
      if (await canvas.count()) {
        const box = (await canvas.boundingBox())!;
        await dpage.mouse.move(box.x + 10, box.y + 10);
        await dpage.mouse.down();
        await dpage.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 8 });
        await dpage.mouse.move(box.x + box.width - 10, box.y + 15, { steps: 8 });
        await dpage.mouse.up();
      }
      await confirm.getByRole("button", { name: /Codes match/ }).click();
      await expect(dpage.locator("body")).toContainText(/Delivered/i);
      await shoot(dpage, "panels/actions", "delivery-code-match-confirm");
      await delivery.close();
      expectClean(problems);
    });
  });

  test.describe("marketer", () => {
    test.use({ storageState: panelState("marketer"), permissions: ["clipboard-read", "clipboard-write"] });
    test("copies a referral link", async ({ page, problems }) => {
      // Plain-http panel hosts are not a secure context, so the browser has no clipboard API there;
      // stand in a minimal one (https production has the real one).
      await page.addInitScript(() => {
        if (!navigator.clipboard) {
          let text = "";
          Object.defineProperty(navigator, "clipboard", { value: { writeText: async (t: string) => void (text = t), readText: async () => text } });
        }
      });
      const code = fixtures().referral_code!;
      await page.goto(panelUrl("marketer", "links"));
      await expect(page.locator("body")).toContainText(code);
      const button = page.locator(`[data-copy-text*="${code}"]`).first();
      await button.click();
      await expect(button).toHaveText(/Copied/);
      const copied = await page.evaluate(() => navigator.clipboard.readText());
      expect(copied).toContain(code);
      await shoot(page, "panels/actions", "marketer-copy-link");
      expectClean(problems);
    });
  });
});
