import { describe, expect, it } from "vitest";
import { getMessages, getT } from "@/lib/i18n";

function keys(node: unknown, prefix = ""): string[] {
  if (!node || typeof node !== "object") return [prefix];
  return Object.entries(node as Record<string, unknown>).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k));
}

describe("i18n catalogues", () => {
  it("EN and AR are key-matched", () => {
    const en = new Set(keys(getMessages("en")));
    const ar = new Set(keys(getMessages("ar")));
    expect([...en].filter((k) => !ar.has(k))).toEqual([]);
    expect([...ar].filter((k) => !en.has(k))).toEqual([]);
  });

  it("merges the namespace files into one catalogue", () => {
    const en = getMessages("en") as Record<string, unknown>;
    for (const ns of ["cart", "status", "commerce", "classifieds", "travel"]) expect(en[ns]).toBeDefined();
  });

  it("getT translates with ICU plurals per locale", () => {
    expect(getT("en")("common.items", { count: 1 })).toBe("1 item");
    expect(getT("en")("common.items", { count: 3 })).toBe("3 items");
    expect(getT("ar")("cart.title")).toBe("السلة");
  });
});
