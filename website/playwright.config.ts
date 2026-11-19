import { defineConfig, devices } from "@playwright/test";
import { globalSetupFor } from "./e2e/support/setup-options";

/**
 * WPT end-to-end run. A production build of the storefront (`next start`, :3000)
 * against the Laravel API and Blade panels (`php artisan serve`, :8000) on the
 * dev databases. The panels answer on `{panel}.nawy.test`; Chromium maps that
 * domain to 127.0.0.1 itself, so /etc/hosts needs no entries.
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
    launchOptions: { args: ["--host-resolver-rules=MAP *.nawy.test 127.0.0.1"] },
  },
  projects: [
    { name: "panel-auth", testMatch: /panel-auth\.setup\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "pages", testMatch: /pages\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "links", testMatch: /links\.spec\.ts/, dependencies: ["pages"], use: { ...devices["Desktop Chrome"] } },
    { name: "flows", testMatch: /flows\/.*\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "panels", testMatch: /panels\.spec\.ts/, dependencies: ["panel-auth"], use: { ...devices["Desktop Chrome"] } },
    // Full browser-test pass per area (customer, admin, vendor, travel, Open Souq, delivery); reports in ../docx.
    { name: "areas", testMatch: /areas\/.*\.spec\.ts/, dependencies: ["panel-auth"], use: { ...devices["Desktop Chrome"], screenshot: "on" } },
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
