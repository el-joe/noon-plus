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

const nextConfig: NextConfig = {
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
