#!/usr/bin/env node
/**
 * Builds the admin-panel report JSON (input of build-docx.mjs) from the step log the
 * admin specs write (e2e/docx/reports/_log/<report>.jsonl, see e2e/specs/areas/admin/helpers.ts)
 * plus the hand-written meta (e2e/docx/reports/meta/<report>.meta.json: title, summary,
 * environment, accounts, issues, notes).
 *
 *   node e2e/docx/admin-report.mjs <report-name>   → e2e/docx/reports/<report-name>.json
 *
 * For every flow the latest recorded test run is used: its steps, its result and its
 * Playwright status. Totals count those latest runs, so nothing that did not run is shown.
 */
import fs from "node:fs";
import path from "node:path";

const name = process.argv[2];
if (!name) {
  console.error("usage: node e2e/docx/admin-report.mjs <report-name>");
  process.exit(2);
}
const dir = path.dirname(new URL(import.meta.url).pathname);
const lines = fs.readFileSync(path.join(dir, "reports/_log", `${name}.jsonl`), "utf8").split("\n").filter(Boolean).map((l) => JSON.parse(l));
const meta = JSON.parse(fs.readFileSync(path.join(dir, "reports/meta", `${name}.meta.json`), "utf8"));

const tests = new Map();
for (const r of lines) if (r.type === "test" && r.id && (!tests.has(r.id) || tests.get(r.id).ts < r.ts)) tests.set(r.id, r);

const flows = [];
const totals = { passed: 0, failed: 0, skipped: 0 };
for (const id of [...tests.keys()].sort()) {
  const t = tests.get(id);
  const start = lines.filter((r) => r.type === "flow" && r.id === id && r.ts <= t.ts).sort((a, b) => b.ts - a.ts)[0];
  if (!start) continue;
  const steps = lines.filter((r) => r.type === "step" && r.id === id && r.ts >= start.ts && r.ts <= t.ts).sort((a, b) => a.n - b.n || a.ts - b.ts);
  const status = t.status === "passed" ? "pass" : t.status === "skipped" ? "skip" : "fail";
  totals[status === "pass" ? "passed" : status === "skip" ? "skipped" : "failed"]++;
  flows.push({
    id,
    name: start.name,
    goal: start.goal,
    preconditions: start.preconditions,
    spec: start.spec,
    result: status,
    run: new Date(t.ts).toISOString().replace("T", " ").slice(0, 16) + " UTC",
    steps: steps.map((s) => ({ action: s.action, expected: s.expected, result: s.result, note: s.note || "", screenshot: s.screenshot })),
    error: t.error,
  });
}

const report = {
  ...meta,
  date: meta.date ?? "2026-11-19",
  totals,
  flows: flows.map((f) => ({ ...f, preconditions: `${f.preconditions ?? ""} Last run: ${f.run}.`.trim() })),
};
const out = path.join(dir, "reports", `${name}.json`);
fs.writeFileSync(out, JSON.stringify(report, null, 2));
console.log(`${out}: ${flows.length} flows, ${totals.passed} passed, ${totals.failed} failed, ${totals.skipped} skipped, ${flows.reduce((n, f) => n + f.steps.length, 0)} steps`);
