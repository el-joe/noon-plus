import { readFileSync } from "node:fs";
import path from "node:path";
import { test, expect } from "@playwright/test";
import { panelUrl } from "../../support/accounts";

/**
 * WP11 extras: an old-platform URL answers 301 on the website itself (proxy.ts
 * → GET /api/v1/legacy-redirects) and on the panels (Laravel 404 fallback).
 * E2eSeeder guarantees `/uae-en/live-streams` → `/uae-en/live` (fixture
 * `legacy_redirect`); the same entry `legacy:redirects` builds.
 */
const fixtures = JSON.parse(readFileSync(path.resolve(__dirname, "../../../../backend/storage/app/private/e2e-fixtures.json"), "utf8")) as {
  legacy_redirect: { from: string; to: string };
};

test("an old storefront URL 301s to its new page", async ({ request, page }) => {
  const { from, to } = fixtures.legacy_redirect;

  const first = await request.get(`${from}?utm_source=old`, { maxRedirects: 0 });
  expect(first.status()).toBe(301);
  const location = new URL(first.headers().location, "http://storefront.invalid");
  expect(location.pathname + location.search).toBe(`${to}?utm_source=old`);

  const response = await page.goto(from);
  expect(new URL(page.url()).pathname).toBe(to);
  expect(response?.status()).toBeLessThan(400);
});

test("a path that is neither a page nor in the map is still a 404", async ({ request }) => {
  const res = await request.get("/uae-en/no-such-legacy-page-xyz", { maxRedirects: 0 });
  expect(res.status()).toBe(404);
});

test("an old portal path on a panel host 301s to the website", async ({ request }) => {
  // The API request context does not use Chromium's *.nawy.test host mapping: call the API server with the panel's Host header.
  const panel = new URL(panelUrl("partner", fixtures.legacy_redirect.from));
  const res = await request.get(`http://127.0.0.1:${panel.port}${panel.pathname}`, { headers: { Host: panel.host }, maxRedirects: 0 });
  expect(res.status()).toBe(301);
  expect(new URL(res.headers().location).pathname).toBe(fixtures.legacy_redirect.to);
});
