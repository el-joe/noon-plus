"use client";

import { createContext, useContext, type ReactNode } from "react";
import type { Market } from "./markets";

const MarketContext = createContext<Market | null>(null);

export function MarketProvider({ market, children }: { market: Market; children: ReactNode }) {
  return <MarketContext.Provider value={market}>{children}</MarketContext.Provider>;
}

export function useMarket(): Market {
  const market = useContext(MarketContext);
  if (!market) throw new Error("useMarket() must be used inside <MarketProvider>.");
  return market;
}

/** Prefix a storefront path with the current market: href("/cart") → "/uae-en/cart". */
export function useHref() {
  const market = useMarket();
  return (path = "") => `/${market.slug}${path === "/" ? "" : path}`;
}
