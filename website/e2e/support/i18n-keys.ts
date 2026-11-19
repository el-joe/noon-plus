import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";

/** Every full key path of the storefront messages (namespaces merged as in lib/i18n.ts). */
function collectKeys(): Set<string> {
  const root = path.resolve(__dirname, "../../messages");
  const keys = new Set<string>();
  const walk = (node: unknown, prefix: string) => {
    if (node && typeof node === "object") {
      for (const [k, v] of Object.entries(node)) walk(v, prefix ? `${prefix}.${k}` : k);
    } else if (prefix.includes(".")) {
      keys.add(prefix);
    }
  };
  for (const locale of ["en", "ar"]) {
    walk(JSON.parse(readFileSync(path.join(root, `${locale}.json`), "utf8")), "");
    for (const file of readdirSync(path.join(root, locale))) walk(JSON.parse(readFileSync(path.join(root, locale, file), "utf8")), "");
  }
  return keys;
}

const KEYS = collectKeys();

/** Dotted tokens in the text that are message keys (what next-intl prints for a missing message). */
export function rawKeysIn(text: string): string[] {
  const tokens = text.match(/[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+/g) ?? [];
  return [...new Set(tokens.filter((t) => KEYS.has(t)))];
}
