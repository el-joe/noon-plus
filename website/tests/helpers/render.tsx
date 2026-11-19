import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, type RenderOptions } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import type { ReactElement, ReactNode } from "react";
import { getMessages } from "@/lib/i18n";
import { MarketProvider } from "@/lib/market-context";
import { parseMarket, type Market } from "@/lib/markets";
import type { Cart, CartLine, Money } from "@/lib/api/schemas";

/** A parsed market for tests ("uae-en", "omn-ar", …); throws on an unknown slug. */
export function market(slug = "uae-en"): Market {
  const parsed = parseMarket(slug);
  if (!parsed) throw new Error(`Unknown test market ${slug}`);
  return parsed;
}

export function testQueryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
}

type Options = Omit<RenderOptions, "wrapper"> & { market?: string; queryClient?: QueryClient };

/** Render inside the storefront providers (react-query, next-intl with the real catalogues, market context). */
export function renderWithProviders(ui: ReactElement, { market: slug = "uae-en", queryClient = testQueryClient(), ...options }: Options = {}) {
  const m = market(slug);
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <NextIntlClientProvider locale={m.locale} messages={getMessages(m.locale)} timeZone={m.country.timeZone}>
          <MarketProvider market={m}>{children}</MarketProvider>
        </NextIntlClientProvider>
      </QueryClientProvider>
    );
  }
  return { ...render(ui, { wrapper: Wrapper, ...options }), queryClient, market: m };
}

export const money = (amount: string, currency = "AED"): Money => ({ amount, currency });

/** A cart line in the CartPresenter shape (defaults: one plain AED item). */
export function cartLine(overrides: Partial<CartLine> = {}): CartLine {
  return {
    listing_id: 1,
    sku: null,
    title: "Wireless Earbuds",
    product_slug: "wireless-earbuds",
    image: null,
    measurement_fields: [],
    measurements_required: false,
    measurements_missing: false,
    quantity: 1,
    unit_price: money("100.00"),
    regular_price: money("100.00"),
    line_total: money("100.00"),
    fulfilment_mode: null,
    flash_sale: null,
    warranty: null,
    warranty_options: [],
    ...overrides,
  };
}

/** A one-shipment cart in the CartPresenter shape. */
export function cart(overrides: Partial<Cart> = {}, lines: CartLine[] = [cartLine()]): Cart {
  const zero = money("0.00");
  return {
    destination: { city: null, area: null },
    shipments: [
      {
        seller: "Nawy Store",
        items: lines,
        subtotal: money("100.00"),
        discount: zero,
        shipping: zero,
        shipping_cost: zero,
        shipping_subsidy: zero,
        free_shipping_threshold_applied: false,
        method: "standard",
        delivery: { from: "2026-11-20", by: "2026-11-20", order_before: null },
        shipping_options: [],
        warranty: zero,
        total: money("100.00"),
      },
    ],
    coupons: [],
    totals: {
      subtotal: money("100.00"),
      discount: zero,
      shipping: zero,
      shipping_subsidy: zero,
      cod_fee: zero,
      warranty: zero,
      total: money("100.00"),
      tax_included: money("4.76"),
      tax_rate: "5",
    },
    warnings: [],
    hold_minutes: 30,
    free_delivery: null,
    ...overrides,
  };
}
