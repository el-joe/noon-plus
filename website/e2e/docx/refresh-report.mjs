#!/usr/bin/env node
/**
 * Refreshes a hand-written report JSON (vendor-panel-*, travel-agency-panel, open-souq) from the
 * Playwright JSON reporter output of the latest run, then builds the .docx:
 *
 *   node e2e/docx/refresh-report.mjs <report> [--results e2e/results.json] [--date 2026-11-19] [--no-build]
 *
 * Every flow id (VEN-01, TRV-03, SOQ-19 …) is matched to the test whose title starts with that id.
 * The flow result becomes the test's last status; a passed flow marks its failed steps passed (their
 * old note is kept, prefixed "Earlier run:"; skipped steps stay skipped); a failed flow gets a
 * "(test end)" step with the error. Totals are recounted; a flow whose test did not run is an error.
 * Optional meta: e2e/docx/reports/meta/<report>.rerun.json {summary?, issues?: [...], notes?: [...]}
 * replaces the summary and is prepended to issues / notes. Missing screenshots fail the refresh.
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const name = args[0];
const opt = (k, d) => (args.includes(k) ? args[args.indexOf(k) + 1] : d);
if (!name) {
  console.error("usage: node e2e/docx/refresh-report.mjs <report> [--results file] [--date yyyy-mm-dd] [--no-build]");
  process.exit(2);
}
const dir = path.dirname(new URL(import.meta.url).pathname);
const reports = path.join(dir, "reports");
const file = path.join(reports, `${name}.json`);
const report = JSON.parse(fs.readFileSync(file, "utf8"));
const results = JSON.parse(fs.readFileSync(path.resolve(opt("--results", path.join(dir, "../results.json"))), "utf8"));
const rerunFile = path.join(reports, "meta", `${name}.rerun.json`);
const rerun = fs.existsSync(rerunFile) ? JSON.parse(fs.readFileSync(rerunFile, "utf8")) : {};

const tests = new Map();
const walk = (suite) => {
  for (const spec of suite.specs ?? []) {
    const id = /^([A-Z]+-\d+[a-z]?)\s/.exec(spec.title)?.[1];
    if (!id) continue;
    for (const t of spec.tests ?? []) {
      const last = t.results?.at(-1);
      tests.set(id, { status: last?.status ?? "skipped", error: last?.error?.message ?? last?.errors?.[0]?.message ?? "", title: spec.title });
    }
  }
  for (const child of suite.suites ?? []) walk(child);
};
for (const suite of results.suites ?? []) walk(suite);

const strip = (s) => String(s ?? "").replace(/\u001b\[[0-9;]*m/g, "").replace(/\s+/g, " ").trim().slice(0, 400);
const totals = { passed: 0, failed: 0, skipped: 0 };
const missing = [];
for (const flow of report.flows) {
  const t = tests.get(flow.id);
  if (!t) throw new Error(`${name}: no test ran for ${flow.id}`);
  flow.result = t.status === "passed" ? "pass" : t.status === "skipped" ? "skip" : "fail";
  totals[{ pass: "passed", fail: "failed", skip: "skipped" }[flow.result]]++;
  flow.steps = flow.steps.filter((s) => s.action !== "(test end)");
  if (flow.result === "pass") {
    for (const s of flow.steps) {
      if (s.result !== "fail") continue; // a step skipped by design (no data on that path) stays skipped
      if (s.note && !s.note.startsWith("Earlier run:")) s.note = `Earlier run: ${s.note} — passes in this run.`;
      s.result = "pass";
    }
  } else if (flow.result === "fail") {
    flow.steps.push({ action: "(test end)", expected: "Test passes", result: "fail", note: strip(t.error) });
  }
  for (const s of flow.steps) {
    if (s.screenshot && !fs.existsSync(path.resolve(reports, s.screenshot))) missing.push(`${flow.id}: ${s.screenshot}`);
  }
}
if (missing.length) throw new Error(`${name}: missing screenshots\n${missing.join("\n")}`);

report.date = opt("--date", "2026-11-19");
report.totals = totals;
if (rerun.summary) report.summary = rerun.summary;
if (rerun.issues) report.issues = [...rerun.issues, ...(report.issues ?? []).filter((i) => !rerun.issues.some((n) => n.id === i.id))];
if (rerun.notes) report.notes = [...rerun.notes, ...(report.notes ?? []).filter((n) => !rerun.notes.includes(n))];
fs.writeFileSync(file, JSON.stringify(report, null, 2));
console.log(`${name}: ${report.flows.length} flows, ${JSON.stringify(totals)}`);
if (!args.includes("--no-build")) execFileSync(process.execPath, [path.join(dir, "build-docx.mjs"), file], { stdio: "inherit" });
