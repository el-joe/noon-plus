import { defineConfig, devices } from "@playwright/test";
import { globalSetupFor } from "./e2e/support/setup-options";
import { BASE_DOMAIN, PANEL_PORT } from "./e2e/support/accounts";

/**
 * WPT end-to-end run. A production build of the storefront (`next start`, :3000)
 * against the Laravel API and Blade panels (`php artisan serve`, :8000) on the
 * dev databases. The panels answer on `{panel}.nawy.test`; Chromium maps that
 * domain to 127.0.0.1 itself, so /etc/hosts needs no entries.
 *
 * Against the Apache vhosts (deploy/local, `*.noon-plus.loc` on port 80):
 * E2E_BASE_DOMAIN=noon-plus.loc E2E_API_PORT=80 E2E_API_URL=http://api.noon-plus.loc
 * E2E_STOREFRONT_URL=http://noon-plus.loc (defaults stay on nawy.test / :8000 / :3000).
 *
 * Before a run: `npm run build` (the e2e script does not build). Global setup
 * seeds the e2e test records (`php artisan db:seed --class=E2eSeeder`, refuses
 * production) and generates the route list from app/[market]/** plus real
 * slugs from the API. Commands and coverage: docs/project/test-report.md.
 * `E2E_NO_GLOBAL_SETUP=1` skips that setup (no seed, no customer sign-in) and
 * reuses the files of the previous run (see e2e/support/setup-options.ts).
 */
const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? "http://localhost:3000";
const API = process.env.E2E_API_URL ?? "http://localhost:8000";

export default defineConfig({
  testDir: "./e2e/specs",
  outputDir: "./e2e/test-results",
  globalSetup: globalSetupFor(process.env),
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: true,
  workers: Number(process.env.E2E_WORKERS ?? 4),
  retries: 0,
  reporter: [["list"], ["html", { outputFolder: "e2e/report", open: "never" }], ["json", { outputFile: "e2e/results.json" }]],
  use: {
    baseURL: STOREFRONT,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    navigationTimeout: 45_000,
    actionTimeout: 15_000,
    launchOptions: { args: [`--host-resolver-rules=MAP *.${BASE_DOMAIN} 127.0.0.1`] },
  },
  projects: [
    { name: "panel-auth", testMatch: /panel-auth\.setup\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "pages", testMatch: /pages\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "links", testMatch: /links\.spec\.ts/, dependencies: ["pages"], use: { ...devices["Desktop Chrome"] } },
    { name: "flows", testMatch: /flows\/.*\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "panels", testMatch: /panels\.spec\.ts/, dependencies: ["panel-auth"], use: { ...devices["Desktop Chrome"] } },
    // Full browser-test pass per area (customer, admin, vendor, travel, Open Souq, delivery); reports in ../docx.
    { name: "areas", testMatch: /areas\/.*\.spec\.ts/, testIgnore: /areas\/live\//, dependencies: ["panel-auth"], use: { ...devices["Desktop Chrome"], screenshot: "on" } },
    // D18: native WebRTC live (two contexts); only this project gets Chromium's fake camera / mic.
    {
      name: "live-webrtc",
      testMatch: /areas\/live\/.*\.spec\.ts/,
      use: {
        ...devices["Desktop Chrome"],
        // The panels run on plain http://admin.nawy.test:8000 locally; getUserMedia needs a secure context.
        // The headless shell ignores --unsafely-treat-insecure-origin-as-secure; the full Chromium (new headless) honours it.
        channel: "chromium",
        launchOptions: {
          args: [
            `--host-resolver-rules=MAP *.${BASE_DOMAIN} 127.0.0.1`,
            "--use-fake-device-for-media-stream",
            "--use-fake-ui-for-media-stream",
            `--unsafely-treat-insecure-origin-as-secure=http://admin.${BASE_DOMAIN}${PANEL_PORT}`,
          ],
        },
      },
    },
  ],
  webServer: [
    {
      command: "php artisan serve --port=8000",
      cwd: "../backend",
      url: `${API}/up`,
      env: { PHP_CLI_SERVER_WORKERS: "6" },
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: "npx next start -p 3000",
      url: STOREFRONT,
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
