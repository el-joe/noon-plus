#!/usr/bin/env node
/**
 * One entry point for the owner: docx/00-summary.docx with one flow per area report
 * (name, number of flows, passed / failed / skipped, .docx file name) plus the Playwright
 * totals per project of the run, built with build-docx.mjs:
 *
 *   node e2e/docx/summary-report.mjs [--results e2e/results.json] [--date 2026-11-19] [--no-build]
 *
 * Inputs: every area report JSON in e2e/docx/reports/ (not *.meta.json) and the JSON reporter output.
 * Fails when a report references a screenshot that does not exist.
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const opt = (k, d) => (args.includes(k) ? args[args.indexOf(k) + 1] : d);
const dir = path.dirname(new URL(import.meta.url).pathname);
const reports = path.join(dir, "reports");
const repoRoot = path.resolve(dir, "../../..");
const date = opt("--date", "2026-11-19");
const results = JSON.parse(fs.readFileSync(path.resolve(opt("--results", path.join(dir, "../results.json"))), "utf8"));

const names = fs.readdirSync(reports).filter((f) => f.endsWith(".json") && !f.endsWith(".meta.json") && !f.startsWith("00-")).map((f) => f.replace(/\.json$/, "")).sort();
const missing = [];
const flows = names.map((name, i) => {
  const r = JSON.parse(fs.readFileSync(path.join(reports, `${name}.json`), "utf8"));
  const t = r.totals ?? { passed: 0, failed: 0, skipped: 0 };
  const steps = r.flows.reduce((n, f) => n + f.steps.length, 0);
  const shots = r.flows.flatMap((f) => f.steps.map((s) => s.screenshot).filter(Boolean));
  for (const s of shots) if (!fs.existsSync(path.resolve(reports, s))) missing.push(`${name}: ${s}`);
  const docx = path.join(repoRoot, "docx", `${name}.docx`);
  const size = fs.existsSync(docx) ? `${Math.round(fs.statSync(docx).size / 1024)} KB` : "not built";
  const issues = r.issues ?? [];
  return {
    id: `SUM-${String(i + 1).padStart(2, "0")}`,
    name: r.title ?? name,
    goal: r.area ?? name,
    preconditions: `Report date ${r.date}; specs: ${(r.specs ?? []).join(", ")}`,
    spec: `docx/${name}.docx`,
    result: t.failed > 0 ? "fail" : r.flows.length && t.skipped === r.flows.length ? "skip" : "pass",
    steps: [{
      action: `${r.flows.length} flows (${steps} steps, ${shots.length} screenshots)`,
      expected: "Every flow passes; no missing screenshots",
      result: t.failed > 0 ? "fail" : "pass",
      note: `passed ${t.passed} · failed ${t.failed} · skipped ${t.skipped} — file docx/${name}.docx (${size}); issues listed: ${issues.length} (${issues.filter((x) => x.status === "fixed").length} fixed, ${issues.filter((x) => x.status !== "fixed").length} open)`,
    }],
  };
});
if (missing.length) throw new Error(`missing screenshots\n${missing.join("\n")}`);

// Playwright totals per project (last result of each test).
const per = {};
const walk = (suite) => {
  for (const spec of suite.specs ?? []) for (const t of spec.tests ?? []) {
    const s = t.status === "flaky" ? "flaky" : (t.results?.at(-1)?.status ?? "skipped");
    per[t.projectName] ??= { passed: 0, failed: 0, skipped: 0, flaky: 0 };
    per[t.projectName][s === "passed" ? "passed" : s === "skipped" ? "skipped" : s === "flaky" ? "flaky" : "failed"]++;
  }
  for (const c of suite.suites ?? []) walk(c);
};
for (const s of results.suites ?? []) walk(s);
const all = Object.values(per).reduce((a, p) => ({ passed: a.passed + p.passed, failed: a.failed + p.failed, skipped: a.skipped + p.skipped, flaky: a.flaky + p.flaky }), { passed: 0, failed: 0, skipped: 0, flaky: 0 });

const totals = { passed: flows.filter((f) => f.result === "pass").length, failed: flows.filter((f) => f.result === "fail").length, skipped: flows.filter((f) => f.result === "skip").length };
const report = {
  title: "Browser tests — summary of all area reports",
  area: "All areas (storefront, customer, checkout, orders, admin, Seller Center, Travel Partner, Open Souq, big catalogue)",
  date,
  summary: `Entry point for the ${flows.length} browser-test reports in docx/. All of them come from the full Playwright run of ${date} against the big development catalogue (5,000 products, about 30,000 offers and 60 sellers per country). Playwright run: ${all.passed} passed, ${all.failed} failed, ${all.skipped} skipped${all.flaky ? `, ${all.flaky} flaky` : ""} (${Math.round((results.stats?.duration ?? 0) / 60000)} min). Each row below is one report: its number of flows, passed / failed / skipped and its file.`,
  environment: [
    "Storefront http://localhost:3000 (npm run build && next start)",
    "API and panels http://localhost:8000 (php artisan serve); panels on *.nawy.test mapped to 127.0.0.1 by Chromium",
    "Dev databases with the big catalogue (php artisan nawy:dev:big-catalog) and E2eSeeder test records",
    "Chromium desktop, Playwright projects panel-auth, pages, links, flows, panels, areas",
  ],
  accounts: [{ role: "All roles", login: "e2e accounts from E2eSeeder (see each report)" }],
  specs: ["website/e2e/specs/**"],
  totals,
  flows,
  issues: [],
  notes: [
    "Playwright totals per project:",
    ...Object.entries(per).map(([p, t]) => `${p}: ${t.passed} passed, ${t.failed} failed, ${t.skipped} skipped${t.flaky ? `, ${t.flaky} flaky` : ""}`),
    "Issues found and fixed in this run are listed in each area report and in docs/project/test-report.md.",
  ],
};
const out = path.join(reports, "00-summary.json");
fs.writeFileSync(out, JSON.stringify(report, null, 2));
console.log(`00-summary: ${flows.length} reports, ${JSON.stringify(totals)}`);
if (!args.includes("--no-build")) execFileSync(process.execPath, [path.join(dir, "build-docx.mjs"), out], { stdio: "inherit" });
