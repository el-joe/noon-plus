/**
 * Allow-list HTML sanitiser for admin-authored CMS HTML (page builder `html`
 * and `seo_text` blocks). Drops script-like elements with their content, every
 * tag not listed and every attribute except safe href/src/alt/title.
 */
const DROP_WITH_CONTENT = /<(script|style|iframe|object|embed|noscript|template|svg|math|form|textarea|select)\b[\s\S]*?<\/\1\s*>/gi;
const ALLOWED = new Set(["p", "br", "strong", "b", "em", "i", "u", "ul", "ol", "li", "h2", "h3", "h4", "a", "span", "div", "img", "blockquote", "table", "thead", "tbody", "tr", "td", "th", "hr", "small"]);
const VOID = new Set(["br", "img", "hr"]);

const NAMED: Record<string, string> = { amp: "&", sol: "/", bsol: "\\", colon: ":", tab: "\t", newline: "\n", period: ".", quot: '"', apos: "'", lt: "<", gt: ">" };

/** Decode the character references a browser would decode inside an attribute value. */
function decodeEntities(value: string): string {
  return value.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);?/gi, (match, ref: string) => {
    if (ref[0] === "#") {
      const code = ref[1] === "x" || ref[1] === "X" ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10);
      return Number.isFinite(code) && code > 0 && code < 0x110000 ? String.fromCodePoint(code) : "";
    }
    return NAMED[ref.toLowerCase()] ?? match;
  });
}

/**
 * QC-072: browsers drop tabs / newlines inside URLs and treat `\` like `/`, so
 * `/\host`, `/\t/host` or `&#47;&#92;host` are protocol-relative (off-site).
 * Only same-site paths (`/x`, never `//` or a backslash), http(s) and, for
 * links, mailto pass.
 */
function safeUrl(value: string, allowMailto: boolean): string | null {
  // eslint-disable-next-line no-control-regex
  const v = decodeEntities(value).replace(/[\u0000-\u001f\u007f]/g, "").trim();
  if (v.includes("\\")) return null;
  if (v.startsWith("/")) return v[1] === "/" ? null : v;
  if (/^https?:\/\/[^/?#]/i.test(v)) return v;
  if (allowMailto && /^mailto:[^\s]/i.test(v)) return v;
  return null;
}

function escapeAttr(value: string): string {
  return value.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

export function sanitizeHtml(input: string): string {
  return input
    .replace(/<!--[\s\S]*?-->/g, "")
    .replace(DROP_WITH_CONTENT, "")
    .replace(/<\/?([a-zA-Z][a-zA-Z0-9]*)\b([^<>]*)>|<|>/g, (match, rawTag: string | undefined, rawAttrs: string | undefined) => {
      if (!rawTag) return match === "<" ? "&lt;" : "&gt;";
      const tag = rawTag.toLowerCase();
      if (!ALLOWED.has(tag)) return "";
      if (match.startsWith("</")) return VOID.has(tag) ? "" : `</${tag}>`;

      const attrs: string[] = [];
      const pattern = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+))/g;
      let found: RegExpExecArray | null;
      while ((found = pattern.exec(rawAttrs ?? "")) !== null) {
        const name = found[1].toLowerCase();
        const value = found[3] ?? found[4] ?? found[5] ?? "";
        if (tag === "a" && name === "href") {
          const url = safeUrl(value, true);
          if (url) attrs.push(`href="${escapeAttr(url)}"`, 'rel="noopener noreferrer"');
        } else if (tag === "img" && name === "src") {
          const url = safeUrl(value, false);
          if (url) attrs.push(`src="${escapeAttr(url)}"`, 'loading="lazy"');
        } else if ((tag === "img" && name === "alt") || name === "title") {
          attrs.push(`${name}="${escapeAttr(value)}"`);
        }
      }
      return `<${tag}${attrs.length ? ` ${attrs.join(" ")}` : ""}>`;
    });
}
