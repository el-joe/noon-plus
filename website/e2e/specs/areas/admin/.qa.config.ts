// Local iteration config for the admin QA specs: same as playwright.config.ts but without the
// global setup (which signs the shared e2e customer in and rewrites e2e/.auth/customer.json on every run).
import base from "../../../../playwright.config";
import { defineConfig } from "@playwright/test";
export default defineConfig({ ...base, globalSetup: undefined, webServer: undefined, testDir: "../../../specs", reporter: [["line"]] });
