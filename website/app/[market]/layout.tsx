import type { Metadata } from "next";
import { Cairo, Inter } from "next/font/google";
import { Suspense } from "react";
import "../globals.css";
import { Providers } from "@/components/providers";
import { Header } from "@/components/layout/header";
import { Footer, FooterFallback } from "@/components/layout/footer";
import { MergeWarningsBanner } from "@/features/cart/merge-warnings-banner";
import { CookieConsent } from "@/features/content/cookie-consent";
import { MiniPlayer } from "@/features/live/mini-player";
import { PreviewBridge } from "@/features/page-builder/preview-bridge";
import { LivePlayerProvider } from "@/features/live/player-context";
import { getMessages, getT } from "@/lib/i18n";
import { MARKET_SLUGS } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const cairo = Cairo({ subsets: ["arabic", "latin"], variable: "--font-cairo", display: "swap" });

// The 16 storefront markets (8 countries × en/ar). proxy.ts redirects any
// other first segment, so the layout only ever sees one of these.
export function generateStaticParams() {
  return MARKET_SLUGS.map((market) => ({ market }));
}

export async function generateMetadata({ params }: LayoutProps<"/[market]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const brand = market.locale === "ar" ? "ناوي" : "Nawy";
  return {
    title: { default: `${brand} ${market.country.name[market.locale]} — ${t("common.tagline")}`, template: `%s | ${brand}` },
    description: t("home.heroSubtitle"),
    icons: { icon: "/brand/logo-mark.png" },
  };
}

export default async function MarketLayout({ children, params }: LayoutProps<"/[market]">) {
  const market = await resolveMarket(params);

  return (
    <html lang={market.locale} dir={market.dir} className={`${inter.variable} ${cairo.variable} h-full antialiased`}>
      <body className="flex min-h-full flex-col bg-page text-ink">
        <Providers market={market} messages={getMessages(market.locale)}>
          <LivePlayerProvider>
            {/* QC-058: checkout pages render <CheckoutChrome>, which hides the store header and footer (globals.css). */}
            <div data-store-chrome className="contents">
              <Header />
            </div>
            <main className="flex-1">
              <MergeWarningsBanner />
              {children}
            </main>
            <div data-store-chrome className="contents">
              <Suspense fallback={<FooterFallback market={market} />}>
                <Footer market={market} />
              </Suspense>
            </div>
            {/* Floating Nawy TV player (keeps playing across pages) and cookie consent (non-essential off by default). */}
            <Suspense fallback={null}>
              <MiniPlayer />
            </Suspense>
            {/* Admin page-builder preview: select / highlight blocks inside the editor's iframe (inactive otherwise). */}
            <PreviewBridge />
            <CookieConsent />
          </LivePlayerProvider>
        </Providers>
      </body>
    </html>
  );
}
