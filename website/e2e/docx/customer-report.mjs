#!/usr/bin/env node
/**
 * Turns the step log of the customer-side area specs
 * (e2e/specs/areas/customer, e2e/specs/areas/orders) into the report JSON
 * for build-docx.mjs, then builds the .docx:
 *
 *   node e2e/docx/customer-report.mjs <report> [--no-build]
 *
 * Inputs:  e2e/docx/reports/_log/<report>.jsonl   (written by areas/customer/kit.ts during the run)
 *          e2e/docx/reports/<report>.meta.json    (title, summary, environment, accounts, issues, notes)
 * Output:  e2e/docx/reports/<report>.json and ../docx/<report>.docx
 *
 * Only the latest run of each flow is used (its "flow" record and the steps
 * logged after it), so the report shows exactly the steps that last ran.
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const [, , name, flag] = process.argv;
if (!name) {
  console.error("usage: node e2e/docx/customer-report.mjs <report> [--no-build]");
  process.exit(2);
}
const dir = path.dirname(new URL(import.meta.url).pathname);
const reports = path.join(dir, "reports");
const log = fs.readFileSync(path.join(reports, "_log", `${name}.jsonl`), "utf8").split("\n").filter(Boolean).map((l) => JSON.parse(l));
const meta = JSON.parse(fs.readFileSync(path.join(reports, `${name}.meta.json`), "utf8"));

const byId = new Map();
for (const rec of log) {
  if (!rec.id) continue;
  const entry = byId.get(rec.id) ?? { flow: null, steps: [], test: null };
  if (rec.type === "flow") {
    entry.flow = rec;
    entry.steps = [];
    entry.test = null;
  } else if (rec.type === "step" && entry.flow && rec.ts >= entry.flow.ts) entry.steps.push(rec);
  else if (rec.type === "test" && entry.flow && rec.ts >= entry.flow.ts) entry.test = rec;
  byId.set(rec.id, entry);
}

const status = { passed: "pass", failed: "fail", timedOut: "fail", interrupted: "fail", skipped: "skip" };
const totals = { passed: 0, failed: 0, skipped: 0 };
const flows = [...byId.entries()]
  .filter(([, e]) => e.flow)
  .sort(([a], [b]) => a.localeCompare(b, "en", { numeric: true }))
  .map(([id, e]) => {
    const result = e.test ? (status[e.test.status] ?? "fail") : e.steps.some((s) => s.result === "fail") ? "fail" : "pass";
    totals[{ pass: "passed", fail: "failed", skip: "skipped" }[result]]++;
    const failedOutside = e.test?.status === "failed" && !e.steps.some((s) => s.result === "fail");
    return {
      id, name: e.flow.name, goal: e.flow.goal, preconditions: e.flow.preconditions, spec: e.flow.spec, result,
      steps: [
        ...e.steps.sort((a, b) => a.n - b.n).map((s) => ({ action: s.action, expected: s.expected, result: s.result, note: s.note ?? "", screenshot: s.screenshot })),
        ...(failedOutside ? [{ action: "(test end)", expected: "No soft-step failures", result: "fail", note: e.test.error ?? "" }] : []),
      ],
    };
  });

const out = { ...meta, totals: { ...totals, ...(meta.totalsNote ? { note: meta.totalsNote } : {}) }, flows };
delete out.totalsNote;
const file = path.join(reports, `${name}.json`);
fs.writeFileSync(file, JSON.stringify(out, null, 2));
console.log(`${name}: ${flows.length} flows, ${JSON.stringify(totals)} → ${path.relative(process.cwd(), file)}`);
if (flag !== "--no-build") execFileSync("node", [path.join(dir, "build-docx.mjs"), file], { stdio: "inherit" });
