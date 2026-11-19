import { describe, expect, it } from "vitest";
import { sanitizeHtml } from "@/lib/sanitize";

describe("sanitizeHtml", () => {
  it("keeps allowed formatting tags", () => {
    expect(sanitizeHtml("<p>Hello <strong>world</strong><br/></p>")).toBe("<p>Hello <strong>world</strong><br></p>");
    expect(sanitizeHtml("<ul><li>a</li></ul>")).toBe("<ul><li>a</li></ul>");
  });

  it("lower-cases tags", () => {
    expect(sanitizeHtml("<P>x</P>")).toBe("<p>x</p>");
  });

  it("drops script, style and iframe together with their content", () => {
    expect(sanitizeHtml("<p>a</p><script>alert(1)</script><p>b</p>")).toBe("<p>a</p><p>b</p>");
    expect(sanitizeHtml("<style>body{display:none}</style>ok")).toBe("ok");
    expect(sanitizeHtml('<iframe src="https://evil.test"></iframe>ok')).toBe("ok");
    expect(sanitizeHtml("<svg><script>x</script></svg>ok")).toBe("ok");
  });

  it("strips tags that are not allow-listed but keeps their text", () => {
    expect(sanitizeHtml("<section><h1>Title</h1></section>")).toBe("Title");
    expect(sanitizeHtml('<button onclick="x()">Go</button>')).toBe("Go");
  });

  it("removes comments", () => {
    expect(sanitizeHtml("a<!-- <script>x</script> -->b")).toBe("ab");
  });

  it("removes event handlers, styles and classes", () => {
    expect(sanitizeHtml('<p onclick="steal()" style="color:red" class="x">t</p>')).toBe("<p>t</p>");
    expect(sanitizeHtml('<img src="/a.png" onerror="alert(1)">')).toBe('<img src="/a.png" loading="lazy">');
  });

  it("keeps safe links and adds rel", () => {
    expect(sanitizeHtml('<a href="https://nawy.test/x">x</a>')).toBe('<a href="https://nawy.test/x" rel="noopener noreferrer">x</a>');
    expect(sanitizeHtml('<a href="/uae-en/deals">d</a>')).toBe('<a href="/uae-en/deals" rel="noopener noreferrer">d</a>');
    expect(sanitizeHtml('<a href="mailto:help@nawy.test">m</a>')).toBe('<a href="mailto:help@nawy.test" rel="noopener noreferrer">m</a>');
  });

  it.each([
    ["javascript:", '<a href="javascript:alert(1)">x</a>'],
    ["entity-encoded javascript:", '<a href="javascript&colon;alert(1)">x</a>'],
    ["data:", '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
    ["protocol-relative", '<a href="//evil.test">x</a>'],
    ["backslash host", '<a href="/\\evil.test">x</a>'],
    ["tab-split protocol-relative", '<a href="/&#9;/evil.test">x</a>'],
    ["encoded slash and backslash", '<a href="&#47;&#92;evil.test">x</a>'],
    ["vbscript", "<a href='vbscript:msgbox'>x</a>"],
  ])("drops unsafe hrefs: %s", (_, html) => {
    expect(sanitizeHtml(html)).toBe("<a>x</a>");
  });

  it("does not allow mailto for images", () => {
    expect(sanitizeHtml('<img src="mailto:a@b.c" alt="a">')).toBe('<img alt="a">');
  });

  it("escapes quotes inside kept attributes", () => {
    expect(sanitizeHtml(`<img alt='say "hi"' src="/a.png">`)).toBe('<img alt="say &quot;hi&quot;" src="/a.png" loading="lazy">');
  });

  it("allows title on any allowed tag but alt only on img", () => {
    expect(sanitizeHtml('<span title="t" alt="a">x</span>')).toBe('<span title="t">x</span>');
  });

  it("escapes stray angle brackets", () => {
    expect(sanitizeHtml("1 < 2 > 0")).toBe("1 &lt; 2 &gt; 0");
  });

  it("neutralises a tag whose attribute contains raw angle brackets", () => {
    const out = sanitizeHtml('<a href="data:text/html,<b>x</b>">x</a>');
    expect(out.startsWith("&lt;a href=")).toBe(true);
    expect(out).not.toContain("<a href");
  });

  it("drops closing tags of void elements", () => {
    expect(sanitizeHtml("<br></br><hr></hr>")).toBe("<br><hr>");
  });
});
