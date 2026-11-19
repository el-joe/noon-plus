import { expect, test, type Page } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { ADMIN, FRESH, panelSession } from "../admin/helpers";

/**
 * D18 — native WebRTC live broadcast end to end, in two browser contexts
 * (project `live-webrtc`: Chromium with a fake camera / mic):
 * the e2e admin opens the studio of a new broadcast in the admin panel, starts
 * the camera and goes live; a guest on the storefront watch page connects over
 * WebRTC (signalling relayed by the API over Reverb) and the <video> plays with
 * real frames; the host ends the show and the viewer shows "ended".
 *
 * Needs: API + panels (:8000), storefront (:3000), Reverb (:8080), the panels
 * built with VITE_REVERB_ENABLED=true. Steps and screenshots go to
 * e2e/docx/reports/live-webrtc-test.json (→ docx/live-webrtc-test.docx).
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? "http://localhost:3000";
const ROOT = path.resolve(__dirname, "../../..");
const SHOTS = path.join(ROOT, "docx/shots/live-webrtc");
const REPORT = path.join(ROOT, "docx/reports/live-webrtc-test.json");
const RUN = Date.now().toString(36).slice(-6);

type Step = { action: string; expected: string; result: "pass" | "fail"; note: string; screenshot: string };
const steps: Step[] = [];

async function step(page: Page, action: string, expected: string, fn: () => Promise<string | void>) {
  const n = steps.length + 1;
  const shot = `live-webrtc-${n}.png`;
  let result: Step["result"] = "pass";
  let note = "";
  let error: unknown;
  await test.step(action, async () => {
    try {
      note = (await fn()) ?? "";
    } catch (e) {
      result = "fail";
      error = e;
      note = String((e as Error)?.message ?? e).split("\n").slice(0, 3).join(" ").slice(0, 400);
    }
  });
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, shot), animations: "disabled" }).catch(() => undefined);
  steps.push({ action, expected, result, note, screenshot: `../shots/live-webrtc/${shot}` });
  if (error) throw error;
}

function writeReport(status: string, error?: string) {
  const passed = status === "passed";
  mkdirSync(path.dirname(REPORT), { recursive: true });
  writeFileSync(
    REPORT,
    JSON.stringify(
      {
        title: "Live broadcasts over native WebRTC — browser test report",
        area: "Live media (owner decision D18)",
        date: new Date().toISOString().slice(0, 10),
        summary:
          "LiveKit was replaced by native WebRTC (one-to-many P2P from the host's browser) with signalling relayed by the API over Reverb private channels. " +
          "This run drives two browser contexts: the admin studio publishes Chromium's fake camera and microphone, a guest storefront viewer joins over WebRTC and plays real frames, then the host ends the show. " +
          (passed ? "Every step passed." : `The run failed: ${error ?? status}.`),
        environment: [
          "Storefront http://localhost:3000 (next start, production build)",
          "API + Blade panels http://localhost:8000 / http://admin.nawy.test:8000 (php artisan serve)",
          "Reverb ws://localhost:8080 (php artisan reverb:start); panels built with VITE_REVERB_ENABLED=true",
          "Chromium (Playwright) with --use-fake-device-for-media-stream --use-fake-ui-for-media-stream (project live-webrtc only)",
          "ICE: STUN only (host candidates on the same machine); no TURN",
        ],
        accounts: [
          { role: "Super admin (host, live_media.manage)", login: "e2e admin (E2eSeeder), email two-step code" },
          { role: "Viewer", login: "guest (not signed in)" },
        ],
        specs: ["e2e/specs/areas/live/webrtc-live.spec.ts"],
        totals: { passed: passed ? 1 : 0, failed: passed ? 0 : 1, skipped: 0 },
        flows: [
          {
            id: "LIVE-01",
            name: "Host goes live from the admin studio; a guest watches over WebRTC; host ends the show",
            goal: "Prove the D18 design end to end: studio publish, join + peer token, signalling over Reverb, P2P media, end-of-show signal.",
            preconditions: "Seeded e2e admin; UAE market; Reverb running.",
            spec: "e2e/specs/areas/live/webrtc-live.spec.ts",
            result: passed ? "pass" : "fail",
            steps,
          },
        ],
        issues: [],
        notes: [
          "Viewer and host run in separate browser contexts (no shared cookies); the viewer is a guest and authorises its private peer channel with the signed peer token.",
          "The capacity cap (stream_full), token expiry, channel authorisation and TURN credentials are covered by Pest (tests/Feature/LiveWebRtcTest.php); the viewer state machine by Vitest.",
        ],
      },
      null,
      2,
    ),
  );
}

test.describe.configure({ mode: "serial" });

test("LIVE-01 host goes live from the studio, guest watches over WebRTC, host ends", async ({ browser }, info) => {
  test.setTimeout(180_000);
  const adminState = await panelSession(browser, "admin", 77);
  const hostContext = await browser.newContext({ storageState: adminState, permissions: ["camera", "microphone"] });
  const host = await hostContext.newPage();
  host.on("dialog", (dialog) => void dialog.accept());
  const viewerContext = await browser.newContext({ ...FRESH, baseURL: STOREFRONT });
  const viewer = await viewerContext.newPage();
  let slug = "";

  try {
    await step(host, "Admin switches the panel to the UAE and creates a live broadcast", "Broadcast page with the studio", async () => {
      await host.goto(ADMIN("/media"));
      await host.evaluate(() => {
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "/country";
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? "";
        form.innerHTML = `<input name="_token" value="${token}"><input name="country" value="uae">`;
        document.body.append(form);
        form.submit();
      });
      await host.waitForLoadState("load");
      await host.goto(ADMIN("/media"));
      await host.locator("#live_title_en").fill(`WebRTC e2e ${RUN}`);
      await host.locator("#live_title_ar").fill(`بث تجريبي ${RUN}`);
      await host.locator("#live_title_en").locator("xpath=ancestor::form").locator('[name="media_mode"]').selectOption("both");
      await host.locator("#live_title_en").locator("xpath=ancestor::form").locator('button[type="submit"]').click();
      await expect(host).toHaveURL(/\/media\/\d+$/);
      await expect(host.locator("[data-live-studio]")).toBeVisible();
      slug = await host.locator("[data-live-studio] script").evaluate((el) => JSON.parse(el.textContent ?? "{}").slug as string);
      expect(slug).toContain("webrtc-e2e");
      return `Stream ${slug}`;
    });

    await step(host, "Start the camera in the studio (fake camera + mic)", "Preview plays; device pickers filled", async () => {
      await host.locator('[data-studio="camera-start"]').click();
      await expect.poll(() => host.locator('[data-studio="preview"]').evaluate((v: HTMLVideoElement) => v.videoWidth), { timeout: 20_000 }).toBeGreaterThan(0);
      await expect(host.locator('[data-studio="video-device"] option')).not.toHaveCount(0);
      await expect(host.locator('[data-studio="audio-device"] option')).not.toHaveCount(0);
    });

    await step(host, "Go live", "Studio live; stream status live", async () => {
      await host.locator('[data-studio="golive"]').click();
      await expect(host.locator("[data-live-studio]")).toHaveAttribute("data-studio-state", "live", { timeout: 20_000 });
      await expect(host.locator('[data-studio="end"]')).toBeVisible();
    });

    await step(viewer, "Guest opens the storefront watch page", "WebRTC viewer mounts and connects", async () => {
      await viewer.goto(`/uae-en/live/${slug}`);
      await expect(viewer.getByTestId("webrtc-viewer")).toBeVisible({ timeout: 30_000 });
    });

    await step(viewer, "The host's video reaches the viewer over WebRTC", "<video> playing with non-zero videoWidth", async () => {
      await expect(viewer.getByTestId("webrtc-viewer")).toHaveAttribute("data-state", "playing", { timeout: 60_000 });
      await expect
        .poll(() => viewer.getByTestId("webrtc-video").evaluate((v: HTMLVideoElement) => ({ w: v.videoWidth, ready: v.readyState, paused: v.paused })), { timeout: 30_000 })
        .toMatchObject({ ready: 4, paused: false });
      const size = await viewer.getByTestId("webrtc-video").evaluate((v: HTMLVideoElement) => `${v.videoWidth}x${v.videoHeight}`);
      expect(Number(size.split("x")[0])).toBeGreaterThan(0);
      // Frames keep coming (not a frozen first frame).
      const t0 = await viewer.getByTestId("webrtc-video").evaluate((v: HTMLVideoElement) => v.currentTime);
      await viewer.waitForTimeout(1500);
      const t1 = await viewer.getByTestId("webrtc-video").evaluate((v: HTMLVideoElement) => v.currentTime);
      expect(t1).toBeGreaterThan(t0);
      return `Video ${size}, currentTime ${t0.toFixed(1)}s → ${t1.toFixed(1)}s`;
    });

    await step(host, "The studio lists the connected viewer and the upload estimate", "1 connected viewer, upload > 0", async () => {
      await expect(host.locator("[data-live-studio]")).toHaveAttribute("data-connected-viewers", "1", { timeout: 20_000 });
      await expect(host.locator('[data-studio="viewers"] li[data-peer-state="connected"]')).toHaveCount(1);
      await expect(host.locator('[data-studio="upload"]')).not.toHaveText("0 kbps");
      return `Upload ${await host.locator('[data-studio="upload"]').textContent()}`;
    });

    await step(host, "Host ends the show (confirm)", "Stream ended in the panel", async () => {
      await host.locator('[data-studio="end"]').click();
      await host.waitForLoadState("load");
      await expect(host.locator("main")).toContainText(/Ended|ended/, { timeout: 20_000 });
    });

    await step(viewer, "The viewer is told the show ended", "Viewer shows the ended state", async () => {
      await expect(viewer.locator("main")).toContainText(/ended|over/i, { timeout: 30_000 });
    });
    writeReport("passed");
  } catch (error) {
    writeReport(info.status ?? "failed", String((error as Error)?.message ?? error).split("\n")[0]);
    throw error;
  } finally {
    await hostContext.close();
    await viewerContext.close();
  }
});
