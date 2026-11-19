import { createTranslator, type Messages } from "next-intl";
import en from "@/messages/en.json";
import ar from "@/messages/ar.json";
import enAccountExtra from "@/messages/en/accountExtra.json";
import arAccountExtra from "@/messages/ar/accountExtra.json";
import enClassifieds from "@/messages/en/classifieds.json";
import arClassifieds from "@/messages/ar/classifieds.json";
import enContent from "@/messages/en/content.json";
import arContent from "@/messages/ar/content.json";
import enCreators from "@/messages/en/creators.json";
import arCreators from "@/messages/ar/creators.json";
import enLive from "@/messages/en/live.json";
import arLive from "@/messages/ar/live.json";
import enPortal from "@/messages/en/portal.json";
import arPortal from "@/messages/ar/portal.json";
import enRealEstate from "@/messages/en/realEstate.json";
import arRealEstate from "@/messages/ar/realEstate.json";
import enShop from "@/messages/en/shop.json";
import arShop from "@/messages/ar/shop.json";
import enTravel from "@/messages/en/travel.json";
import arTravel from "@/messages/ar/travel.json";
import enAccountPlus from "@/messages/en/accountPlus.json";
import arAccountPlus from "@/messages/ar/accountPlus.json";
import enStorefront from "@/messages/en/storefront.json";
import arStorefront from "@/messages/ar/storefront.json";
import enDiscovery from "@/messages/en/discovery.json";
import arDiscovery from "@/messages/ar/discovery.json";
import enCommerce from "@/messages/en/commerce.json";
import arCommerce from "@/messages/ar/commerce.json";
import enPortalPlus from "@/messages/en/portalPlus.json";
import arPortalPlus from "@/messages/ar/portalPlus.json";
import enCategoryPage from "@/messages/en/categoryPage.json";
import arCategoryPage from "@/messages/ar/categoryPage.json";
import type { Locale } from "./markets";

/**
 * next-intl messages per locale. `messages/{locale}.json` holds the core
 * namespaces; each vertical / area keeps its own namespace file in
 * `messages/{locale}/{namespace}.json` (merged here, typed in global.d.ts).
 * EN and AR must stay key-matched.
 *
 * Server components translate with `getT` (no request-bound config, so it
 * works inside prerendered shells); client components use `useTranslations`
 * under <NextIntlClientProvider>.
 */
export const enMessages = {
  ...en,
  ...enAccountExtra,
  ...enClassifieds,
  ...enContent,
  ...enCreators,
  ...enLive,
  ...enPortal,
  ...enRealEstate,
  ...enShop,
  ...enTravel,
  ...enAccountPlus,
  ...enStorefront,
  ...enDiscovery,
  ...enCommerce,
  ...enPortalPlus,
  ...enCategoryPage,
};

const arMessages = {
  ...ar,
  ...arAccountExtra,
  ...arClassifieds,
  ...arContent,
  ...arCreators,
  ...arLive,
  ...arPortal,
  ...arRealEstate,
  ...arShop,
  ...arTravel,
  ...arAccountPlus,
  ...arStorefront,
  ...arDiscovery,
  ...arCommerce,
  ...arPortalPlus,
  ...arCategoryPage,
};

const catalogues: Record<Locale, Messages> = { en: enMessages, ar: arMessages as Messages };

export function getMessages(locale: Locale): Messages {
  return catalogues[locale];
}

export function getT(locale: Locale) {
  return createTranslator({ locale, messages: catalogues[locale] });
}

export type ServerT = ReturnType<typeof getT>;
