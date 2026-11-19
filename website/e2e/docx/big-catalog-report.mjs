#!/usr/bin/env node
/**
 * Report of the big-catalogue live test (e2e/specs/areas/catalog/big-catalog.spec.ts
 * and mega-menu.spec.ts) for build-docx.mjs, then builds docx/big-catalog-live-test.docx:
 *
 *   node e2e/docx/big-catalog-report.mjs [--no-build]
 *
 * Inputs:  e2e/results/big-catalog/{market}.json   (page timings, counts, screenshots per market)
 *          e2e/results/mega-menu.json               (mega menu run, optional)
 *          e2e/results/big-catalog-seed.json        (seed counts per country, optional:
 *                                                    {countries: [{code, products, variants, offers, vendors, media, pages}]})
 *          e2e/results.json                          (Playwright JSON reporter: pass / fail totals)
 * Output:  e2e/docx/reports/big-catalog-live-test.json and ../docx/big-catalog-live-test.docx
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const dir = path.dirname(new URL(import.meta.url).pathname);
const e2e = path.resolve(dir, "..");
const reports = path.join(dir, "reports");
const read = (file, fallback = null) => (fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, "utf8")) : fallback);
const rel = (fromE2e) => (fromE2e ? path.relative(reports, path.join(e2e, fromE2e)) : undefined);

const fragDir = path.join(e2e, "results/big-catalog");
const markets = fs.existsSync(fragDir)
  ? fs.readdirSync(fragDir).filter((f) => f.endsWith(".json")).sort().map((f) => read(path.join(fragDir, f)))
  : [];
const menu = read(path.join(e2e, "results/mega-menu.json"));
const seed = read(path.join(e2e, "results/big-catalog-seed.json"));
const run = read(path.join(e2e, "results.json"));
const serial = read(path.join(e2e, "results/big-catalog-api-serial.json"));

// Playwright totals for the two specs.
const totals = { passed: 0, failed: 0, skipped: 0 };
const failedTitles = [];
const walk = (suite) => {
  for (const spec of suite.specs ?? []) {
    if (!/big-catalog|mega-menu/.test(spec.file ?? suite.file ?? "")) continue;
    for (const t of spec.tests ?? []) {
      const status = t.results?.at(-1)?.status;
      if (status === "passed") totals.passed++;
      else if (status === "skipped") totals.skipped++;
      else {
        totals.failed++;
        failedTitles.push(spec.title);
      }
    }
  }
  for (const child of suite.suites ?? []) walk(child);
};
for (const suite of run?.suites ?? []) walk(suite);

const avg = (xs) => (xs.length ? Math.round(xs.reduce((a, b) => a + b, 0) / xs.length) : 0);
const fmtChecks = (checks) => Object.entries(checks ?? {}).map(([k, v]) => `${k}=${v}`).join(", ");
const PAGE_NAMES = { home: "Home page", category: "Electronics category", product: "Product with several sellers", search: "Search “smart”" };
const EXPECT = {
  home: "All page-builder sections render with real products, prices in the local currency, coupon codes and countdown",
  category: "Real result count, filter rail changes results, infinite scroll loads more, prices in local currency",
  product: "Product page lists the buy box and the other sellers' offers",
  search: "Search returns many products with local prices",
};

const flows = markets.map((m) => ({
  id: `BC-${m.market.toUpperCase()}`,
  name: `${m.market} — home, category, product, search`,
  goal: `Live storefront with the big catalogue in ${m.market} (${m.currency})`,
  preconditions: "nawy:dev:big-catalog seeded; servers on :8000 / :3000",
  spec: "e2e/specs/areas/catalog/big-catalog.spec.ts",
  result: m.steps.length === 4 && m.steps.every((s) => s.ok) ? "pass" : "fail",
  steps: m.steps.map((s) => ({
    action: `${PAGE_NAMES[s.page] ?? s.page}: open ${s.url}`,
    expected: EXPECT[s.page] ?? "",
    result: s.ok ? "pass" : "fail",
    note: `load ${s.load_ms} ms; ${fmtChecks(s.checks)}; broken images ${s.broken_images}; console errors ${s.console_errors}${s.error ? `; error: ${s.error}` : ""}`,
    screenshot: rel(s.screenshot),
  })),
}));

if (menu?.markets?.length) {
  flows.push(...menu.markets.map((m) => ({
    id: `MM-${m.market.toUpperCase()}`,
    name: `${m.market} — header mega menu`,
    goal: "Every top-level category opens a flyout with level-2 columns, level-3 links, category image and top brands",
    preconditions: "Big catalogue seeded",
    spec: "e2e/specs/areas/catalog/mega-menu.spec.ts",
    result: (m.failures ?? []).length === 0 ? "pass" : "fail",
    steps: [{
      action: `Hover ${m.top_level} top-level categories`,
      expected: "Columns, level-3 links, image loads, top brands; follow a level-3 link and a brand tile",
      result: (m.failures ?? []).length === 0 ? "pass" : "fail",
      note: `opened ${m.opened}/${m.top_level}; api ${m.api_ms} ms; page ${m.page_load_ms} ms; level-3 follow ${m.level3?.ok ? "ok" : "failed"}; brand follow ${m.brand?.ok ? "ok" : "failed"}${(m.failures ?? []).length ? `; ${m.failures.join("; ")}` : ""}`,
      screenshot: m.screenshot ? rel(m.screenshot.startsWith("docx/") ? m.screenshot : path.relative(e2e, path.resolve(e2e, m.screenshot))) : undefined,
    }],
  })));
}

const timingRows = markets.map((m) => {
  const by = Object.fromEntries(m.steps.map((s) => [s.page, s]));
  return `${m.market}: home ${by.home?.load_ms ?? "—"} ms · category ${by.category?.load_ms ?? "—"} ms (${by.category?.checks?.total ?? "—"} results, filter ${by.category?.checks?.filter_ms ?? "—"} ms, scroll ${by.category?.checks?.first_page ?? "—"}→${by.category?.checks?.after_scroll ?? "—"}) · product ${by.product?.load_ms ?? "—"} ms (${by.product?.checks?.sellers ?? "—"} sellers) · search ${by.search?.load_ms ?? "—"} ms (${by.search?.checks?.total ?? "—"}) · API listing ${m.api.listing} ms, facets ${m.api.facets} ms, filtered ${m.api.filtered} ms, search ${m.api.search} ms, home page ${m.api.home_page} ms`;
});
const allSteps = markets.flatMap((m) => m.steps);
const seedRows = (seed?.countries ?? []).map((c) => `${c.code}: ${c.products} products · ${c.variants} variants · ${c.offers} offers · ${c.vendors} vendors · ${c.media} media rows · ${c.pages} published pages`);

const report = {
  title: "Big catalogue — live storefront test",
  area: "Storefront catalogue (8 countries × EN/AR)",
  date: new Date().toISOString().slice(0, 10),
  summary: `Live test of the storefront with the big development catalogue (about 5,000 products, 30,000 offers and 60 sellers per country). ${markets.length} markets ran: home, Electronics category, a product with several sellers and search. Average page load (domcontentloaded → main): home ${avg(allSteps.filter((s) => s.page === "home").map((s) => s.load_ms))} ms, category ${avg(allSteps.filter((s) => s.page === "category").map((s) => s.load_ms))} ms, product ${avg(allSteps.filter((s) => s.page === "product").map((s) => s.load_ms))} ms, search ${avg(allSteps.filter((s) => s.page === "search").map((s) => s.load_ms))} ms. ${serial ? `API one request at a time (warm): category listing avg ${avg(Object.values(serial).map((t) => t.listing))} ms, facets avg ${avg(Object.values(serial).map((t) => t.facets))} ms, facets with colour + FBN + arrives-by filters avg ${avg(Object.values(serial).map((t) => t.filtered_facets))} ms (max ${Math.max(...Object.values(serial).map((t) => t.filtered_facets))} ms), search avg ${avg(Object.values(serial).map((t) => t.search))} ms.` : `Average category listing API ${avg(markets.map((m) => m.api.listing))} ms, facets ${avg(markets.map((m) => m.api.facets))} ms.`}${menu ? ` Mega menu: ${menu.totals?.opened ?? "?"} of ${menu.totals?.top_level_categories ?? "?"} flyouts opened, average open ${menu.totals?.open_ms_avg ?? "?"} ms.` : ""}`,
  environment: [
    "Storefront http://localhost:3000 (npm run build && next start)",
    "API and panels http://localhost:8000 (php artisan serve, 8 workers)",
    "Dev databases: central noon-plus + nawy_{uae,omn,ksa,egy,kwt,qat,bhr,jor}",
    "Seed: php artisan nawy:dev:big-catalog --country=all --products=5000 --vendors=60",
    "Chromium desktop 1440×900, Playwright project areas (E2E_NO_GLOBAL_SETUP=1, --no-deps)",
  ],
  accounts: [{ role: "Guest shopper", login: "not signed in" }],
  specs: ["e2e/specs/areas/catalog/big-catalog.spec.ts", "e2e/specs/areas/catalog/mega-menu.spec.ts"],
  totals,
  flows,
  issues: failedTitles.map((title, i) => ({ id: `BC-${String(i + 1).padStart(3, "0")}`, severity: "major", area: "Storefront", description: `Failed: ${title}`, status: "open", fix: "" })),
  notes: [
    "Seed counts per country:",
    ...seedRows,
    "Timings per market:",
    ...timingRows,
    ...(serial
      ? [
          "API timings measured one request at a time after the run (warm, ms; the in-test API timings above were taken while 4 browsers loaded pages in parallel):",
          ...Object.entries(serial).map(([m, t]) => `${m}: ${Object.entries(t).map(([k, v]) => `${k} ${v}`).join(" · ")}`),
        ]
      : []),
    "Images: products reuse the 10 demo images stored once under storage/products/demo (product_media rows per product and per variant).",
  ],
};

fs.mkdirSync(reports, { recursive: true });
const out = path.join(reports, "big-catalog-live-test.json");
fs.writeFileSync(out, JSON.stringify(report, null, 2));
console.log(`wrote ${out} (${flows.length} flows, ${totals.passed} passed / ${totals.failed} failed)`);
if (process.argv[2] !== "--no-build") execFileSync(process.execPath, [path.join(dir, "build-docx.mjs"), out], { stdio: "inherit" });
