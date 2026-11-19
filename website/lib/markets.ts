/**
 * The 8 storefront countries (MASTER_PLAN §4a). The backend is the source of
 * truth (`GET /countries`); this static copy lets the router validate a
 * `[market]` segment and render the country switcher without a request.
 * `name` mirrors the API's `names: {en, ar}` (G-A8); keep the two in step.
 */
export const LOCALES = ["en", "ar"] as const;
export type Locale = (typeof LOCALES)[number];

export type Country = {
  code: string;
  iso2: string;
  currency: string;
  decimals: number;
  defaultLocale: Locale;
  timeZone: string;
  name: { en: string; ar: string };
};

export const COUNTRIES: Country[] = [
  { code: "uae", timeZone: "Asia/Dubai", iso2: "AE", currency: "AED", decimals: 2, defaultLocale: "en", name: { en: "United Arab Emirates", ar: "الإمارات العربية المتحدة" } },
  { code: "omn", timeZone: "Asia/Muscat", iso2: "OM", currency: "OMR", decimals: 3, defaultLocale: "ar", name: { en: "Oman", ar: "عُمان" } },
  { code: "ksa", timeZone: "Asia/Riyadh", iso2: "SA", currency: "SAR", decimals: 2, defaultLocale: "ar", name: { en: "Saudi Arabia", ar: "المملكة العربية السعودية" } },
  { code: "egy", timeZone: "Africa/Cairo", iso2: "EG", currency: "EGP", decimals: 2, defaultLocale: "ar", name: { en: "Egypt", ar: "مصر" } },
  { code: "kwt", timeZone: "Asia/Kuwait", iso2: "KW", currency: "KWD", decimals: 3, defaultLocale: "ar", name: { en: "Kuwait", ar: "الكويت" } },
  { code: "qat", timeZone: "Asia/Qatar", iso2: "QA", currency: "QAR", decimals: 2, defaultLocale: "ar", name: { en: "Qatar", ar: "قطر" } },
  { code: "bhr", timeZone: "Asia/Bahrain", iso2: "BH", currency: "BHD", decimals: 3, defaultLocale: "ar", name: { en: "Bahrain", ar: "البحرين" } },
  { code: "jor", timeZone: "Asia/Amman", iso2: "JO", currency: "JOD", decimals: 3, defaultLocale: "ar", name: { en: "Jordan", ar: "الأردن" } },
];

export type Market = {
  /** e.g. "uae-en" — the URL segment and the API `{market}` parameter. */
  slug: string;
  country: Country;
  locale: Locale;
  dir: "ltr" | "rtl";
};

export const MARKET_SLUGS: string[] = COUNTRIES.flatMap((c) => LOCALES.map((l) => `${c.code}-${l}`));

export const DEFAULT_MARKET = process.env.NEXT_PUBLIC_DEFAULT_MARKET && MARKET_SLUGS.includes(process.env.NEXT_PUBLIC_DEFAULT_MARKET)
  ? process.env.NEXT_PUBLIC_DEFAULT_MARKET
  : "uae-en";

export const MARKET_COOKIE = "nawy_market";

export function parseMarket(slug: string | undefined | null): Market | null {
  const match = /^([a-z]{3})-([a-z]{2})$/.exec(slug ?? "");
  if (!match) return null;
  const country = COUNTRIES.find((c) => c.code === match[1]);
  const locale = LOCALES.find((l) => l === match[2]);
  if (!country || !locale) return null;
  return { slug: `${country.code}-${locale}`, country, locale, dir: locale === "ar" ? "rtl" : "ltr" };
}

export function marketSlug(countryCode: string, locale: Locale): string {
  return `${countryCode}-${locale}`;
}

/** Swap the market segment of a pathname, keeping the rest ("/uae-en/cart" → "/omn-ar/cart"). */
export function switchMarketPath(pathname: string, target: string): string {
  const parts = pathname.split("/");
  if (parseMarket(parts[1])) {
    parts[1] = target;
    return parts.join("/") || `/${target}`;
  }
  return `/${target}`;
}
