"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { NextIntlClientProvider, type Messages } from "next-intl";
import { useState, type ReactNode } from "react";
import { MarketProvider } from "@/lib/market-context";
import type { Market } from "@/lib/markets";

export function Providers({ market, messages, children }: { market: Market; messages: Messages; children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: { queries: { staleTime: 30_000, refetchOnWindowFocus: false, retry: 1 } },
      }),
  );

  return (
    <QueryClientProvider client={client}>
      <NextIntlClientProvider locale={market.locale} messages={messages} timeZone={market.country.timeZone}>
        <MarketProvider market={market}>{children}</MarketProvider>
      </NextIntlClientProvider>
    </QueryClientProvider>
  );
}
