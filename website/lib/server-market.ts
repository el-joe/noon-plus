import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getT, type ServerT } from "./i18n";
import { parseMarket, type Market } from "./markets";

/** Resolve the `[market]` param or render the 404 page. */
export async function resolveMarket(params: Promise<{ market: string }>): Promise<Market> {
  const market = parseMarket((await params).market);
  if (!market) notFound();
  return market;
}

export function marketHref(market: Market, path = ""): string {
  return `/${market.slug}${path === "/" ? "" : path}`;
}

type TitleKey = Parameters<ServerT>[0];
type TitleParams = { market: string; number?: string };

/**
 * `generateMetadata` for private pages (QA-048): a localised title (the
 * layout template adds "| Nawy") and noindex. `{number}` in the message is
 * filled from the route's `[number]` param.
 */
export function privatePageMetadata(key: TitleKey) {
  return async ({ params }: { params: Promise<TitleParams> }): Promise<Metadata> => {
    const resolved = await params;
    const market = parseMarket(resolved.market);
    if (!market) return {};
    const t = getT(market.locale) as unknown as (key: string, values?: Record<string, string>) => string;
    let number = resolved.number ?? "";
    try {
      number = decodeURIComponent(number);
    } catch {
      // Keep the raw segment.
    }
    return { title: t(key, { number }), robots: { index: false, follow: false } };
  };
}
