import type { NextConfig } from "next";

/**
 * QC-042: legacy seller-portal URLs (PORTAL_PAGES.md) → their storefront
 * pages. Paths without a market are first sent to the visitor's market by
 * proxy.ts, then matched here. Portal country prefixes (`/uae/helpcenter`)
 * map to the country's English market.
 */
const MARKET = ":market([a-z]{3}-(?:en|ar))";
const PORTAL_COUNTRY = ":country(uae|omn|ksa|egy|kwt|qat|bhr|jor)";
const LEGACY_PORTAL: [string, string][] = [
  ["/companies", "/join"],
  ["/companies/open-souq", "/join/classified"],
  ["/companies/travel-agency", "/sell/travel-agency"],
  ["/companies/travel-agency/register", "/join/travel_agency"],
  ["/companies/travel-agency/register/success", "/join/status"],
  ["/register", "/join/vendor"],
  ["/register/success", "/join/status"],
  ["/vendors", "/sell"],
  ["/vendors/register", "/join/vendor"],
  ["/vendors/register/success", "/join/status"],
  ["/helpcenter", "/help"],
  ["/helpcenter/search", "/help/search"],
  ["/helpcenter/category/:slug", "/help/collections/:slug"],
  ["/helpcenter/article/:slug", "/help/articles/:slug"],
  ["/advertise/:section(sellers|brands|advertisers|product|display|request)", "/advertise"],
];

/**
 * Framing: the storefront may be framed only by the admin page builder's
 * live preview (requests carrying the signed `?preview=` token, which the
 * API checks) and only from the admin panel origins; everything else denies
 * framing (clickjacking). Admin origins: NAWY_ADMIN_ORIGINS (space separated),
 * else derived from NEXT_PUBLIC_API_URL (`api.host` → `admin.host`, http + https).
 */
function adminOrigins(): string {
  if (process.env.NAWY_ADMIN_ORIGINS) return process.env.NAWY_ADMIN_ORIGINS;
  try {
    const api = new URL(process.env.NEXT_PUBLIC_API_URL ?? "");
    const host = api.host.replace(/^api\./, "admin.");
    return host === api.host ? "'self'" : `https://${host} http://${host}`;
  } catch {
    return "'self'";
  }
}

const nextConfig: NextConfig = {
  async headers() {
    return [
      {
        source: "/:path*",
        missing: [{ type: "query", key: "preview" }],
        headers: [
          { key: "Content-Security-Policy", value: "frame-ancestors 'none'" },
          { key: "X-Frame-Options", value: "DENY" },
        ],
      },
      {
        source: "/:path*",
        has: [{ type: "query", key: "preview" }],
        headers: [
          { key: "Content-Security-Policy", value: `frame-ancestors ${adminOrigins()}` },
          { key: "X-Robots-Tag", value: "noindex" },
        ],
      },
    ];
  },
  experimental: {
    agentFeedback: true,
    // The root layout lives under the dynamic `[market]` segment, so URLs
    // that match no route use app/global-not-found.tsx.
    globalNotFound: true,
  },
  cacheComponents: true,
  async redirects() {
    return [
      { source: `/${PORTAL_COUNTRY}/:rest(helpcenter|adsupport|advertise)/:path*`, destination: "/:country-en/:rest/:path*", permanent: true },
      ...LEGACY_PORTAL.map(([from, to]) => ({ source: `/${MARKET}${from}`, destination: `/:market${to}`, permanent: true })),
    ];
  },
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
