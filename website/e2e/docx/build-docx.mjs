#!/usr/bin/env node
/**
 * Builds a browser-test report (.docx) from a JSON description of the flows
 * a Playwright run covered. Usage:
 *
 *   node e2e/docx/build-docx.mjs <report.json> [out.docx]
 *
 * Default output: ../docx/<basename of the json>.docx (repo-root docx/ folder).
 * Screenshot paths in the JSON are resolved relative to the JSON file.
 *
 * JSON shape (see e2e/docx/README.md):
 * {
 *   "title": "Admin panel — browser test report",
 *   "area": "Admin panel",
 *   "date": "2026-11-19",
 *   "summary": "One paragraph of what was tested and the outcome.",
 *   "environment": ["Storefront http://localhost:3000 (next start)", "..."],
 *   "accounts": [{"role": "Super admin", "login": "e2e admin (E2eSeeder)"}],
 *   "specs": ["e2e/specs/areas/admin/*.spec.ts"],
 *   "totals": {"passed": 0, "failed": 0, "skipped": 0},
 *   "flows": [{
 *     "id": "ADM-01", "name": "Sign in with 2FA", "goal": "...",
 *     "preconditions": "...", "spec": "e2e/specs/areas/admin/auth.spec.ts",
 *     "result": "pass" | "fail" | "skip",
 *     "steps": [{"action": "...", "expected": "...", "result": "pass", "note": "", "screenshot": "shots/adm-01-1.png"}]
 *   }],
 *   "issues": [{"id": "BT-001", "severity": "major", "area": "...", "description": "...", "status": "fixed" | "open", "fix": "..."}],
 *   "notes": ["..."]
 * }
 */
import fs from "node:fs";
import path from "node:path";
import {
  AlignmentType, BorderStyle, Document, HeadingLevel, ImageRun, Packer, Paragraph, ShadingType,
  Table, TableCell, TableOfContents, TableRow, TextRun, WidthType, PageBreak, Header, Footer, PageNumber,
} from "docx";

const [, , input, outArg] = process.argv;
if (!input) {
  console.error("usage: node e2e/docx/build-docx.mjs <report.json> [out.docx]");
  process.exit(2);
}
const jsonPath = path.resolve(input);
const baseDir = path.dirname(jsonPath);
const report = JSON.parse(fs.readFileSync(jsonPath, "utf8"));
const repoRoot = path.resolve(path.dirname(new URL(import.meta.url).pathname), "../../..");
const out = path.resolve(outArg ?? path.join(repoRoot, "docx", `${path.basename(jsonPath, ".json")}.docx`));

const COLORS = { pass: "1E7B34", fail: "B3261E", skip: "8A6D00", head: "1F2937", shade: "F3F4F6" };
const label = (r) => ({ pass: "PASS", fail: "FAIL", skip: "SKIPPED" })[r] ?? String(r ?? "").toUpperCase();

const p = (text, opts = {}) => new Paragraph({ spacing: { after: 120 }, ...opts, children: [new TextRun({ text: String(text ?? ""), ...(opts.run ?? {}) })] });
const h = (text, level) => new Paragraph({ heading: level, spacing: { before: 240, after: 120 }, children: [new TextRun(String(text))] });
const resultRun = (r) => new TextRun({ text: label(r), bold: true, color: COLORS[r] ?? COLORS.head });

function cell(content, { header = false, width } = {}) {
  const children = Array.isArray(content) ? content : [content instanceof Paragraph ? content : new Paragraph({ children: [content instanceof TextRun ? content : new TextRun({ text: String(content ?? ""), bold: header })] })];
  return new TableCell({
    children,
    width: width ? { size: width, type: WidthType.PERCENTAGE } : undefined,
    shading: header ? { type: ShadingType.CLEAR, color: "auto", fill: COLORS.shade } : undefined,
    margins: { top: 60, bottom: 60, left: 80, right: 80 },
  });
}
function table(headers, rows, widths) {
  return new Table({
    width: { size: 100, type: WidthType.PERCENTAGE },
    borders: Object.fromEntries(["top", "bottom", "left", "right", "insideHorizontal", "insideVertical"].map((k) => [k, { style: BorderStyle.SINGLE, size: 4, color: "D1D5DB" }])),
    rows: [
      new TableRow({ tableHeader: true, children: headers.map((t, i) => cell(t, { header: true, width: widths?.[i] })) }),
      ...rows.map((r) => new TableRow({ children: r.map((c, i) => cell(c, { width: widths?.[i] })) })),
    ],
  });
}

function image(rel) {
  if (!rel) return null;
  const file = path.isAbsolute(rel) ? rel : path.join(baseDir, rel);
  if (!fs.existsSync(file)) return p(`(screenshot missing: ${rel})`, { run: { italics: true, color: "6B7280" } });
  const data = fs.readFileSync(file);
  // PNG header width/height for scaling to page width (max 600 px wide).
  let w = 1280, hgt = 800;
  if (data.readUInt32BE(12) === 0x49484452) { w = data.readUInt32BE(16); hgt = data.readUInt32BE(20); }
  const maxW = 600; const scale = Math.min(1, maxW / w);
  return new Paragraph({ spacing: { after: 160 }, children: [new ImageRun({ type: "png", data, transformation: { width: Math.round(w * scale), height: Math.round(hgt * scale) } })] });
}

const flows = report.flows ?? [];
const count = (r) => flows.filter((f) => f.result === r).length;
const totals = report.totals ?? { passed: count("pass"), failed: count("fail"), skipped: count("skip") };

const children = [
  new Paragraph({ heading: HeadingLevel.TITLE, children: [new TextRun(report.title ?? `${report.area} — browser test report`)] }),
  p(`Area: ${report.area ?? ""}`), p(`Date: ${report.date ?? new Date().toISOString().slice(0, 10)}`),
  p(`Result: ${totals.passed} passed · ${totals.failed} failed · ${totals.skipped} skipped (Playwright tests); ${flows.length} documented flows`, { run: { bold: true } }),
  h("Contents", HeadingLevel.HEADING_1),
  new TableOfContents("Contents", { hyperlink: true, headingStyleRange: "1-2" }),
  p("(Right-click the table and choose Update Field to refresh page numbers.)", { run: { italics: true, color: "6B7280", size: 18 } }),
  new Paragraph({ children: [new PageBreak()] }),
  h("1. Summary", HeadingLevel.HEADING_1), p(report.summary),
  h("2. Test environment", HeadingLevel.HEADING_1), ...(report.environment ?? []).map((e) => p(`• ${e}`)),
];
if (report.accounts?.length) {
  children.push(h("3. Test accounts and roles", HeadingLevel.HEADING_1), table(["Role", "Account"], report.accounts.map((a) => [a.role, a.login]), [35, 65]));
}
if (report.specs?.length) {
  children.push(h("4. Playwright specs", HeadingLevel.HEADING_1), ...report.specs.map((s) => p(`• ${s}`)));
}
children.push(
  h("5. Flow overview", HeadingLevel.HEADING_1),
  table(["ID", "Flow", "Steps", "Result"], flows.map((f) => [f.id, f.name, String(f.steps?.length ?? 0), new Paragraph({ children: [resultRun(f.result)] })]), [12, 63, 10, 15]),
  h("6. Flows in detail", HeadingLevel.HEADING_1),
);
for (const f of flows) {
  children.push(h(`${f.id} · ${f.name}`, HeadingLevel.HEADING_2));
  if (f.goal) children.push(p(`Goal: ${f.goal}`));
  if (f.preconditions) children.push(p(`Preconditions: ${f.preconditions}`));
  if (f.spec) children.push(p(`Spec: ${f.spec}`, { run: { color: "6B7280" } }));
  children.push(new Paragraph({ spacing: { after: 120 }, children: [new TextRun("Result: "), resultRun(f.result)] }));
  children.push(table(["#", "Action", "Expected", "Result"], (f.steps ?? []).map((s, i) => [String(i + 1), s.action + (s.note ? `\nNote: ${s.note}` : ""), s.expected ?? "", new Paragraph({ children: [resultRun(s.result ?? f.result)] })]), [6, 44, 38, 12]));
  for (const [i, s] of (f.steps ?? []).entries()) {
    if (!s.screenshot) continue;
    children.push(p(`Step ${i + 1} — ${s.action}`, { run: { italics: true, size: 18 } }));
    const img = image(s.screenshot); if (img) children.push(img);
  }
}
children.push(h("7. Issues found", HeadingLevel.HEADING_1));
if (report.issues?.length) {
  children.push(table(["ID", "Severity", "Description", "Status / fix"], report.issues.map((i) => [i.id, i.severity, `${i.area ? `[${i.area}] ` : ""}${i.description}`, `${i.status}${i.fix ? ` — ${i.fix}` : ""}`]), [12, 12, 46, 30]));
} else children.push(p("No issues found."));
if (report.notes?.length) children.push(h("8. Notes", HeadingLevel.HEADING_1), ...report.notes.map((n) => p(`• ${n}`)));

const doc = new Document({
  creator: "Nawy QA", title: report.title ?? report.area, features: { updateFields: true },
  styles: { default: { document: { run: { font: "Calibri", size: 21 } } } },
  sections: [{
    properties: { page: { margin: { top: 1000, bottom: 1000, left: 1000, right: 1000 } } },
    headers: { default: new Header({ children: [p(report.title ?? report.area, { alignment: AlignmentType.RIGHT, run: { size: 16, color: "6B7280" } })] }) },
    footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: ["Page ", PageNumber.CURRENT, " of ", PageNumber.TOTAL_PAGES], size: 16, color: "6B7280" })] })] }) },
    children,
  }],
});
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, await Packer.toBuffer(doc));
console.log(`wrote ${out} (${flows.length} flows)`);
