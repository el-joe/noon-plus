import { describe, expect, it } from "vitest";
import { background, bool, item, num, resolveLink, safeUrl, str, strings } from "@/features/page-builder/settings";
import { market } from "../helpers/render";

describe("page-builder settings readers", () => {
  it("str prefers the localised key, then the plain key, then a {en, ar} object", () => {
    expect(str({ title_ar: "عروض", title: "Deals" }, "title", "ar")).toBe("عروض");
    expect(str({ title_ar: "  ", title: "Deals" }, "title", "ar")).toBe("Deals");
    expect(str({ title: { en: "Deals", ar: "عروض" } }, "title", "ar")).toBe("عروض");
    expect(str({ n: 5 }, "n")).toBe("5");
    expect(str({}, "title", "en")).toBeNull();
  });

  it("num and bool tolerate editor spellings", () => {
    expect(num({ n: "4" }, "n", 1)).toBe(4);
    expect(num({ n: "" }, "n", 1)).toBe(1);
    expect(num({ n: null }, "n", 1)).toBe(1);
    expect(num({ n: "x" }, "n", 1)).toBe(1);
    expect(bool({ a: "1", b: 0, c: "false", d: "maybe" }, "a")).toBe(true);
    expect(bool({ b: 0 }, "b", true)).toBe(false);
    expect(bool({ c: "false" }, "c", true)).toBe(false);
    expect(bool({ d: "maybe" }, "d", true)).toBe(true);
  });

  it("strings splits comma / newline lists", () => {
    expect(strings({ k: "a, b\nc,," }, "k")).toEqual(["a", "b", "c"]);
    expect(strings({ k: ["a", 1, " ", "b"] }, "k")).toEqual(["a", "b"]);
  });

  it("safeUrl only keeps http(s) and root-relative URLs", () => {
    expect(safeUrl("https://cdn.test/a.png")).toBe("https://cdn.test/a.png");
    expect(safeUrl("/media/a.png")).toBe("/media/a.png");
    expect(safeUrl("javascript:alert(1)")).toBeNull();
    expect(safeUrl("data:image/png;base64,xx")).toBeNull();
  });

  it("resolveLink prefixes the market and drops unsafe schemes", () => {
    const m = market("omn-ar");
    expect(resolveLink("/deals", m)).toBe("/omn-ar/deals");
    expect(resolveLink("/", m)).toBe("/omn-ar");
    expect(resolveLink("/uae-en/deals", m)).toBe("/uae-en/deals");
    expect(resolveLink("https://x.test", m)).toBe("https://x.test");
    expect(resolveLink("javascript:alert(1)", m)).toBeNull();
    expect(resolveLink(null, m)).toBeNull();
  });

  it("item reads any of the editor's spellings", () => {
    const it1 = item({ image_url: "/a.png", title_en: "Phones", url: "/phones", badge: "New" }, "en");
    expect(it1).toMatchObject({ image: "/a.png", label: "Phones", link: "/phones", alt: "Phones", sticker: "New" });
    expect(item({ image: "javascript:x" }, "en").image).toBeNull();
  });

  it("background only accepts hex colours and design tokens", () => {
    expect(background({ background: "#fff" })).toBe("#fff");
    expect(background({ background: { color: "var(--color-brand)" } })).toBe("var(--color-brand)");
    expect(background({ background: "red; position:fixed" })).toBeUndefined();
    expect(background({ background: "url(x)" })).toBeUndefined();
  });
});
