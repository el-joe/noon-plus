import { notFound } from "next/navigation";
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
