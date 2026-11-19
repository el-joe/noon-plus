import type { Browser, Page } from "@playwright/test";
import { test, expect, expectClean, settle } from "../../../support/fixtures";
import { panelState } from "../../../support/accounts";
import { rejectOptionalCookies } from "../../../support/storefront";
import { freshCustomer, panel, png, runId, shot, souq, tinkerUae } from "./support/souq";

/**
 * Open Souq, posting as a private customer (a fresh test customer per test):
 * the post-ad wizard with photos, video and location, the per-category photo
 * limit, the seller contract of a category that requires one, and My ads
 * (edit a draft, pause / resume, mark sold, renew an expired ad, archive).
 * Ads go live after the admin approves them (admin panel used for that only).
 */
test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

async function chooseCategory(page: Page, ...names: string[]) {
  for (const name of names) await page.locator("main").getByRole("button", { name: new RegExp(`^${name}`) }).click();
  await page.getByRole("button", { name: "Continue" }).click();
}

/** Category → details → location (saves the draft) → photos. Returns the ad number. */
async function startAd(page: Page, opts: { categories: string[]; title: string; price: string; city?: string; area?: string }) {
  await page.goto("/uae-en/classified/post");
  await settle(page);
  await expect(page.getByRole("heading", { level: 1, name: "Post an ad" })).toBeVisible();
  await chooseCategory(page, ...opts.categories);
  await page.getByLabel("Title").fill(opts.title);
  await page.getByLabel("Description").fill("Posted by the Open Souq browser test. Works perfectly.");
  await page.getByLabel(/^Price \(AED\)/).fill(opts.price);
  await page.getByLabel("Price is negotiable").check();
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("City").fill(opts.city ?? "Dubai");
  await page.getByLabel("Area").fill(opts.area ?? "Al Barsha");
  await page.getByLabel(/Contact mobile/).fill("+971501239876");
  await page.getByRole("button", { name: "Save draft and continue" }).click();
  await expect(page.locator("main")).toContainText("Add photos");
  const number = await page.evaluate(async () => {
    // The wizard keeps the draft number in the URL or state; read it from My ads API through the page's own proxy.
    const res = await fetch(`/api/nawy/uae-en/classifieds/mine`, { headers: { Accept: "application/json" } });
    const json = (await res.json()) as { data: { number: string; title: string }[] };
    return json.data;
  });
  const own = number.find((a) => a.title === opts.title);
  expect(own, "draft created").toBeTruthy();
  return own!.number;
}

async function addPhotos(page: Page, count: number) {
  const files = Array.from({ length: count }, (_, i) => png(`photo-${i + 1}.png`, [40 * i + 60, 120, 200 - 30 * i]));
  await page.locator('main input[type="file"][accept^="image"]').setInputFiles(files);
}

/** Admin approves the ad (admin panel, review queue) so the flow can continue. */
async function adminApprove(browser: Browser, number: string) {
  const id = Number(number.replace(/^CL-[A-Z]{2}-0*/, ""));
  const ctx = await browser.newContext({ storageState: panelState("admin") });
  const admin = await ctx.newPage();
  await admin.goto(panel("admin", "classifieds"));
  const form = admin.locator(`form[action$="/classifieds/${id}/approve"]`);
  await expect(form).toHaveCount(1);
  await form.locator('button[type="submit"]').click();
  await expect(admin.locator(`form[action$="/classifieds/${id}/approve"]`)).toHaveCount(0);
  await ctx.close();
}

/**
 * BT-SOQ-02 (fixed in code, needs a storefront rebuild): My ads links every
 * title to the public page, whose prefetch 404s for non-live ads. SOQ-20
 * checks the fix; the other flows ignore that one console line.
 */
const ignoreMyAdsPrefetch404 = (problems: { console: string[] }) =>
  (problems.console = problems.console.filter((m) => !/\/account\/my-ads :: Failed to load resource: .*404.*\/classified\/find\/CL-[A-Z]{2}-\d+\?_rsc=/.test(m)));

const myAdRow = (page: Page, number: string) => page.locator("main li").filter({ hasText: `#${number}` });

test("SOQ-15 post an ad: category, details, location, photos, submit", async ({ page, problems }) => {
  test.setTimeout(150_000);
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  const title = `E2E iPad Air M2 ${runId()}`;
  const number = await startAd(page, { categories: ["Mobiles & electronics"], title, price: "1850" });
  await shot(page, "SOQ-15", 1);
  await expect(page.locator("main")).toContainText("Up to 20 photos");
  await addPhotos(page, 2);
  await expect(page.locator("main")).toContainText("2 of 20 photos", { timeout: 30_000 });
  await shot(page, "SOQ-15", 2);


  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.locator("main")).toContainText("Check your ad");
  await expect(page.locator("main")).toContainText(title);
  await expect(page.locator("main")).toContainText("Dubai · Al Barsha");
  await shot(page, "SOQ-15", 3);
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expect(page.locator("main")).toContainText("Your ad is in review");
  await expect(page.locator("main")).toContainText(number);
  await shot(page, "SOQ-15", 4);

  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  await expect(myAdRow(page, number)).toContainText("In review");
  await shot(page, "SOQ-15", 5);
  ignoreMyAdsPrefetch404(problems);
  expectClean(problems);
});

test("SOQ-16 photo limit of the category (2 photos)", async ({ page, problems }) => {
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  await startAd(page, { categories: ["E2E Two-photo goods"], title: `E2E two-photo item ${runId()}`, price: "75" });
  await expect(page.locator("main")).toContainText("Up to 2 photos");
  await addPhotos(page, 3);
  await expect(page.locator("main")).toContainText("2 of 2 photos", { timeout: 30_000 });
  await expect(page.locator("main")).toContainText("so 1 photo was not added");
  await expect(page.locator("main")).toContainText("This ad has the maximum of 2 photos");
  await expect(page.locator('main input[type="file"][accept^="image"]')).toHaveCount(0);
  // Category without video: no video picker.
  await expect(page.locator('main input[type="file"][accept^="video"]')).toHaveCount(0);
  await shot(page, "SOQ-16", 1);
  ignoreMyAdsPrefetch404(problems);
  expectClean(problems);
});

test("SOQ-17 category that requires a contract: sign, then review", async ({ page, problems }) => {
  test.setTimeout(120_000);
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  const title = `E2E signed watch ${runId()}`;
  const number = await startAd(page, { categories: ["E2E Contract goods"], title, price: "640" });
  await addPhotos(page, 1);
  await expect(page.locator("main")).toContainText("1 of 20 photos", { timeout: 30_000 });
  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.locator("main")).toContainText("This category needs the seller contract");
  await shot(page, "SOQ-17", 1);
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expect(page.locator("main")).toContainText("E2E Souq seller contract · v1");
  await expect(page.locator("main")).toContainText("e2e test terms");
  const sign = page.getByRole("button", { name: "Sign and send for review" });
  await expect(sign).toBeDisabled();
  await page.getByLabel("Your full name (signature)").fill("E2E Souq Poster");
  await page.getByLabel("I have read and accept the contract").check();
  await shot(page, "SOQ-17", 2);
  await sign.click();
  await expect(page.locator("main")).toContainText("Your ad is in review");
  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  await expect(myAdRow(page, number)).toContainText("In review");
  await shot(page, "SOQ-17", 3);
  ignoreMyAdsPrefetch404(problems);
  expectClean(problems);
});

test("SOQ-18 my ads: edit draft, approve, pause, resume, mark sold, renew, archive", async ({ page, browser, problems }) => {
  test.setTimeout(180_000);
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  const title = `E2E bike ${runId()}`;
  const number = await startAd(page, { categories: ["Mobiles & electronics"], title, price: "500" });
  await addPhotos(page, 1);
  await expect(page.locator("main")).toContainText("1 of 20 photos", { timeout: 30_000 });

  // Edit the draft from My ads.
  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  await expect(myAdRow(page, number)).toContainText("Draft");
  await shot(page, "SOQ-18", 1);
  await myAdRow(page, number).getByRole("link", { name: "Edit" }).click();
  await expect(page).toHaveURL(new RegExp(`classified/post\\?number=${number}`));
  await expect(page.getByRole("heading", { level: 1, name: "Edit your ad" })).toBeVisible();
  // Step through the wizard (the draft reopens on its saved steps) and change the title on Details.
  for (let i = 0; i < 6 && !(await page.getByRole("heading", { name: "Check your ad" }).isVisible()); i++) {
    const titleField = page.getByLabel("Title");
    if (await titleField.isVisible()) await titleField.fill(`${title} (edited)`);
    const save = page.getByRole("button", { name: "Save draft and continue" });
    await ((await save.isVisible()) ? save : page.getByRole("button", { name: "Continue" })).click();
    await page.waitForTimeout(500);
  }
  await expect(page.locator("main")).toContainText(`${title} (edited)`);
  await shot(page, "SOQ-18", 2);
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expect(page.locator("main")).toContainText("Your ad is in review");

  await adminApprove(browser, number);
  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  const row = myAdRow(page, number);
  await expect(row).toContainText(`${title} (edited)`);
  await expect(row).toContainText("Live");
  await shot(page, "SOQ-18", 3);

  await row.getByRole("button", { name: "Pause" }).click();
  await expect(row).toContainText("Paused");
  await shot(page, "SOQ-18", 4);
  await row.getByRole("button", { name: "Resume" }).click();
  await expect(row).toContainText("Live");

  await row.getByRole("button", { name: "Mark as sold" }).click();
  await row.locator('input[type="number"]').fill("480");
  await shot(page, "SOQ-18", 5);
  await row.getByRole("button", { name: "Confirm sold" }).click();
  await expect(row).toContainText("Sold");
  await row.getByRole("button", { name: "Not sold" }).click();
  await expect(row).toContainText("Live");
  // BT-SOQ-04: render the public page while the ad is live (it is archived below).
  const live = await page.goto(`/uae-en/classified/find/${number}`);
  expect(live?.status()).toBe(200);
  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  await expect(row).toContainText("Live");

  // The listing period ends (simulated on this test ad): Renew relists it for free.
  tinkerUae(`$ad = App\\Domains\\Classifieds\\Models\\ClassifiedAd::where('number', '${number}')->sole(); $ad->forceFill(['status' => App\\Domains\\Classifieds\\Enums\\ClassifiedAdStatus::Expired, 'expires_at' => now()->subDay()])->save();`);
  await page.reload();
  await settle(page);
  await expect(row).toContainText("Expired");
  await shot(page, "SOQ-18", 6);
  await row.getByRole("button", { name: "Renew" }).click();
  await expect(row).toContainText(/Live|In review/);
  await shot(page, "SOQ-18", 7);

  await row.getByRole("button", { name: "Archive" }).click();
  await expect(row).toContainText("Archived");
  await expect(row.getByRole("button", { name: "Duplicate" })).toBeVisible();
  await shot(page, "SOQ-18", 8);
  // An archived ad is gone from the public site: a real 404 even though the page was shown while live
  // (BT-SOQ-04, fixed: the proxy no longer answers classified ad lookups from its 60 s "found" cache).
  const gone = await page.goto(`/uae-en/classified/find/${number}`);
  expect(gone?.status()).toBe(404);
  await expect(page.locator("main")).toContainText("We couldn't find that page");
  await expect(page.locator('head meta[name="robots"]').first()).toHaveAttribute("content", /noindex/);
  problems.console = problems.console.filter((m) => !m.includes(`/classified/find/${number} :: Failed to load resource: the server responded with a status of 404`));
  ignoreMyAdsPrefetch404(problems);
  expectClean(problems);
});

test("SOQ-19 video on an ad (WebM recorded in the browser)", async ({ page, problems }) => {
  test.setTimeout(120_000);
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  await startAd(page, { categories: ["Mobiles & electronics"], title: `E2E video ad ${runId()}`, price: "990" });
  await expect(page.locator("main")).toContainText("Short video (optional)");
  await expect(page.locator("main")).toContainText("up to 60 seconds");
  // A short WebM recorded in the browser (MediaRecorder) as the optional video.
  const video = await page.evaluate(async () => {
    const canvas = Object.assign(document.createElement("canvas"), { width: 160, height: 120 });
    const ctx = canvas.getContext("2d")!;
    const stream = canvas.captureStream(15);
    const rec = new MediaRecorder(stream, { mimeType: "video/webm" });
    const chunks: Blob[] = [];
    rec.ondataavailable = (e) => chunks.push(e.data);
    const stopped = new Promise((r) => (rec.onstop = r));
    rec.start(100);
    let f = 0;
    const timer = setInterval(() => {
      ctx.fillStyle = `hsl(${(f += 20) % 360} 70% 50%)`;
      ctx.fillRect(0, 0, 160, 120);
    }, 60);
    await new Promise((r) => setTimeout(r, 1500));
    rec.stop();
    await stopped;
    clearInterval(timer);
    const buf = new Uint8Array(await new Blob(chunks, { type: "video/webm" }).arrayBuffer());
    return Array.from(buf);
  });
  await page.locator('main input[type="file"][accept^="video"]').setInputFiles({ name: "walkaround.webm", mimeType: "video/webm", buffer: Buffer.from(video) });
  // Uploaded: the wizard shows the video with the duration it read ("Video 0:02") and a Remove button.
  await expect(page.locator("main").getByText(/^Video\s*0:0[1-3]$/)).toBeVisible({ timeout: 30_000 });
  await shot(page, "SOQ-19", 1);
  expectClean(problems);
});

test("SOQ-20 my ads links only live ads to the public page", async ({ page }) => {
  souq();
  await freshCustomer(page, `e2e-souq-poster-${runId()}@nawy.test`);
  const title = `E2E link check ${runId()}`;
  await startAd(page, { categories: ["Mobiles & electronics"], title, price: "10" });
  await page.goto("/uae-en/account/my-ads");
  await settle(page);
  await expect(page.locator("main")).toContainText(title);
  await shot(page, "SOQ-20", 1);
  // A draft has no public page, so its title must not link to one (BT-SOQ-02).
  await expect(page.locator("main").getByRole("link", { name: title })).toHaveCount(0);
});
