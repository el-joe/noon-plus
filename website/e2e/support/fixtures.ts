import { test as base, expect, type Page, type ConsoleMessage } from "@playwright/test";
import { mkdirSync } from "node:fs";
import path from "node:path";
import { rawKeysIn } from "./i18n-keys";

export type PageProblems = { console: string[]; pageErrors: string[] };

/**
 * Every test gets `problems`: console errors and uncaught page errors seen on
 * any page of its context. `expectClean()` fails the test when there are any.
 */
export const test = base.extend<{ problems: PageProblems }>({
  problems: async ({ context }, use) => {
    const problems: PageProblems = { console: [], pageErrors: [] };
    const watch = (page: Page) => {
      page.on("console", (msg: ConsoleMessage) => {
        if (msg.type() === "error") problems.console.push(`${page.url()} :: ${msg.text()}${msg.location().url && msg.location().url !== page.url() ? ` [${msg.location().url}]` : ""}`);
      });
      page.on("pageerror", (error) => problems.pageErrors.push(`${page.url()} :: ${error.message}`));
    };
    context.pages().forEach(watch);
    context.on("page", watch);
    await use(problems);
  },
});

export { expect };

export function expectClean(problems: PageProblems) {
  expect.soft(problems.pageErrors, "uncaught page errors").toEqual([]);
  expect.soft(problems.console, "console errors").toEqual([]);
}

export const SCREENSHOT_ROOT = path.resolve(__dirname, "../screenshots");

/** e2e/screenshots/{group}/{name}.png, full page. */
export async function shoot(page: Page, group: string, name: string) {
  const dir = path.join(SCREENSHOT_ROOT, group);
  mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `${name.replace(/[^a-zA-Z0-9._-]+/g, "_").replace(/^_+|_+$/g, "") || "home"}.png`);
  await page.screenshot({ path: file, fullPage: true, animations: "disabled" });
  return path.relative(path.resolve(__dirname, "../.."), file);
}

/** Lets client components hydrate and fetch: network idle, bounded. */
export async function settle(page: Page, ms = 8_000) {
  await page.waitForLoadState("load");
  await page.waitForLoadState("networkidle", { timeout: ms }).catch(() => undefined);
}

/** `dir`/`lang` of the document and no raw next-intl keys in the visible text. */
export async function expectLocale(page: Page, locale: "en" | "ar") {
  const html = page.locator("html");
  await expect(html).toHaveAttribute("lang", locale);
  await expect(html).toHaveAttribute("dir", locale === "ar" ? "rtl" : "ltr");
  const text = await page.locator("body").innerText();
  expect.soft(rawKeysIn(text), "raw translation keys").toEqual([]);
}

/** Same-origin internal links on the page (no fragments, no mailto/tel). */
export async function internalLinks(page: Page): Promise<string[]> {
  const origin = new URL(page.url()).origin;
  const hrefs = await page.$$eval("a[href]", (as) => as.map((a) => (a as HTMLAnchorElement).href));
  return [...new Set(hrefs.filter((h) => h.startsWith(origin)).map((h) => h.split("#")[0]))];
}
